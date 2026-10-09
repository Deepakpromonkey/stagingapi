<?php

namespace App\Services\Carrier;

use App\Models\CarrierConnectRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;

/**
 * Produces and stores the signed copy of a broker agreement.
 *
 * The carrier only draws their signature; it is stamped at the position the
 * wizard sends (the bottom of the last page) with a short "signed
 * electronically" line beneath it, and the result is stored next to the
 * original so both the broker and the carrier can download what was signed.
 *
 * The server stamps the broker's original file itself, so the stored copy
 * cannot have been altered in the browser. FPDI's free parser does not read
 * PDFs saved with compressed cross-reference streams (PDF 1.5+, which is what
 * Word and most modern tools write), so such a file is first rewritten by qpdf
 * with a classic cross-reference table - lossless, the pages are untouched -
 * and stamped from that. Only if that also fails is a copy the wizard stamped
 * in the browser used. The original, the signature image and its placement
 * are all retained either way, so any signed copy can be checked against them.
 */
class SignedAgreementService
{
    /** Signature width as a share of the page width — matches the wizard's preview. */
    private const SIGNATURE_WIDTH_RATIO = 0.22;

    /**
     * @return array{disk: string, path: string, source: string}|null
     */
    public function createFor(
        CarrierConnectRequest $connectRequest,
        ?UploadedFile $browserCopy = null
    ): ?array {
        $bytes = $this->stamp($connectRequest);
        $source = 'server';

        if ($bytes === null && $browserCopy !== null) {
            $bytes = $this->readBrowserCopy($browserCopy);
            $source = 'browser';
        }

        if ($bytes === null) {
            Log::error('No signed agreement could be produced', [
                'connect_request' => $connectRequest->uuid,
            ]);

            return null;
        }

        $disk = config('filesystems.default');

        $path = 'carrier-signed-agreements/'.$connectRequest->company_id.'/'
            .$connectRequest->uuid.'-'.now()->format('YmdHis').'-'.Str::random(6).'.pdf';

        if (! Storage::disk($disk)->put($path, $bytes)) {
            Log::error('Could not store the signed agreement', [
                'connect_request' => $connectRequest->uuid,
            ]);

            return null;
        }

        return ['disk' => $disk, 'path' => $path, 'source' => $source];
    }

    /**
     * Stamp the signature onto the broker's original agreement.
     *
     * Returns null rather than throwing when the original cannot be read, so
     * the caller can fall back to the browser's copy.
     */
    public function stamp(CarrierConnectRequest $connectRequest): ?string
    {
        $agreement = $connectRequest->agreementDocument;

        if (! $agreement || ! $connectRequest->signature_path) {
            return null;
        }

        $original = $this->tempCopy($agreement->disk, $agreement->file_path, 'pdf');
        $signature = $this->tempCopy(
            $connectRequest->signature_disk,
            $connectRequest->signature_path,
            pathinfo($connectRequest->signature_path, PATHINFO_EXTENSION) ?: 'png'
        );

        $rewritten = null;

        try {
            if (! $original || ! $signature) {
                return null;
            }

            try {
                return $this->stampFile($connectRequest, $original, $signature);
            } catch (CrossReferenceException $e) {
                $rewritten = $this->withClassicXref($original);

                if ($rewritten === null) {
                    throw $e;
                }

                return $this->stampFile($connectRequest, $rewritten, $signature);
            }
        } catch (\Throwable $e) {
            Log::warning('Server-side agreement stamping unavailable for this PDF', [
                'connect_request' => $connectRequest->uuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            foreach ([$original, $signature, $rewritten] as $file) {
                if ($file && is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function stampFile(CarrierConnectRequest $connectRequest, string $original, string $signature): string
    {
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(false);

        $pageCount = $pdf->setSourceFile($original);

        // Clamped so a stale page number cannot leave the signature off
        // the document altogether.
        $target = min(max((int) $connectRequest->signature_page, 1), $pageCount);

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);

            if ($page === $target) {
                $this->drawSignature($pdf, $connectRequest, $signature, $size['width'], $size['height']);
            }
        }

        return $pdf->Output('S');
    }

    /**
     * The same PDF rewritten with a classic cross-reference table and no
     * object streams, which FPDI's free parser can read. Null when qpdf is
     * not installed or cannot read the file either.
     */
    private function withClassicXref(string $original): ?string
    {
        $rewritten = $original.'-classic.pdf';

        try {
            $result = Process::timeout(30)->run([
                'qpdf', '--object-streams=disable', '--decrypt', $original, $rewritten,
            ]);
        } catch (\Throwable $e) {
            Log::warning('qpdf could not rewrite the agreement', ['error' => $e->getMessage()]);

            return null;
        }

        // qpdf exits 3 when it repaired something but still wrote the file.
        if (! in_array($result->exitCode(), [0, 3], true) || ! is_file($rewritten)) {
            Log::warning('qpdf could not rewrite the agreement', [
                'exit_code' => $result->exitCode(),
                'error' => trim($result->errorOutput()) ?: 'qpdf not available',
            ]);

            @unlink($rewritten);

            return null;
        }

        return $rewritten;
    }

    private function drawSignature(
        Fpdi $pdf,
        CarrierConnectRequest $connectRequest,
        string $signature,
        float $pageWidth,
        float $pageHeight
    ): void {
        [$pixelWidth, $pixelHeight] = getimagesize($signature) ?: [1, 1];

        $width = $pageWidth * self::SIGNATURE_WIDTH_RATIO;
        $height = $width * ($pixelHeight / max($pixelWidth, 1));

        // The stored position is the signature's centre, in percent of the page.
        $x = $pageWidth * ((float) $connectRequest->signature_x_pct / 100) - $width / 2;
        $y = $pageHeight * ((float) $connectRequest->signature_y_pct / 100) - $height / 2;

        // Kept on the page, with room for the caption underneath.
        $x = min(max($x, 0), $pageWidth - $width);
        $y = min(max($y, 0), $pageHeight - $height - 4);

        $pdf->Image($signature, $x, $y, $width, $height);

        $pdf->SetFont('Helvetica', '', 6);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY($x, $y + $height);
        $pdf->Cell(
            $width,
            3,
            $this->latin1('Signed electronically by '.($connectRequest->carrier_legal_name ?: 'the carrier')),
            0,
            2,
            'C'
        );
        $pdf->Cell(
            $width,
            3,
            ($connectRequest->signed_at ?? now())->format('M j, Y g:i A T'),
            0,
            0,
            'C'
        );
    }

    /**
     * The wizard's pdf-lib copy, accepted only if it is actually a PDF.
     */
    private function readBrowserCopy(UploadedFile $file): ?string
    {
        $bytes = @file_get_contents($file->getRealPath());

        if ($bytes === false || ! str_starts_with($bytes, '%PDF-')) {
            return null;
        }

        return $bytes;
    }

    /**
     * FPDI and FPDF read from local paths, and the files may live on S3.
     */
    private function tempCopy(?string $disk, ?string $path, string $extension): ?string
    {
        if (! $disk || ! $path || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'agreement-');

        if ($temp === false) {
            return null;
        }

        $local = $temp.'.'.$extension;
        rename($temp, $local);

        file_put_contents($local, Storage::disk($disk)->get($path));

        return $local;
    }

    // FPDF's core fonts are Latin-1; anything outside it would print as junk.
    private function latin1(string $text): string
    {
        return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
    }
}

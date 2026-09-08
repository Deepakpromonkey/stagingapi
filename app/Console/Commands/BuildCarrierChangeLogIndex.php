<?php

namespace App\Console\Commands;

use App\Services\Carrier\CarrierChangeLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Index the FMCSA change log export so a carrier's history can be read without
 * importing gigabytes into a database.
 *
 * The export groups every change by DOT number, so all this has to record is
 * where each carrier's run of lines starts and how long it is. That turns a
 * multi-gigabyte file into a few megabytes of index that
 * CarrierChangeLogService binary-searches, followed by one ranged read.
 *
 * Run it again whenever the export is replaced; it is a full rebuild and
 * refuses to repeat itself while the source is unchanged.
 *
 * @see CarrierChangeLogService
 */
class BuildCarrierChangeLogIndex extends Command
{
    protected $signature = 'carriers:index-change-log
                            {--source= : Read from this local file instead of the configured object}
                            {--bytes= : Stop after this many bytes — for smoke testing a prefix}
                            {--force : Rebuild even when the source has not changed}';

    protected $description = 'Index the FMCSA change log export by DOT number';

    protected const MAGIC = 'DTCLIDX1';

    protected const VERSION = 1;

    protected const HEADER_BYTES = 128;

    protected const CHUNK_BYTES = 1048576;

    public function handle(): int
    {
        $source = $this->option('source');

        [$stream, $size, $etag] = $source
            ? $this->openLocal($source)
            : $this->openRemote();

        if (! $stream) {
            return self::FAILURE;
        }

        $indexPath = storage_path('app/'.ltrim((string) config('carriers.change_log.index_path'), '/'));

        if (! $this->option('force') && $this->isCurrent($indexPath, $etag)) {
            $this->info('Index is already current for this export. Use --force to rebuild.');
            fclose($stream);

            return self::SUCCESS;
        }

        $stopAt = $this->option('bytes') ? (int) $this->option('bytes') : null;

        $this->info('Indexing '.($source ?: config('carriers.change_log.key')).($size ? ' ('.$this->humanBytes($size).')' : ''));

        $runs = $this->scan($stream, $stopAt, $size);

        fclose($stream);

        if (empty($runs)) {
            $this->error('No DOT numbers found — is this the right file?');

            return self::FAILURE;
        }

        $this->write($indexPath, $runs, $size, $etag);

        return self::SUCCESS;
    }

    /**
     * One pass over the export, collecting the byte range each DOT occupies.
     *
     * Lines are located by scanning for newlines rather than decoded, and the
     * DOT is pulled out of the raw text: at tens of millions of records a
     * json_decode per line is the difference between minutes and hours.
     *
     * @return array<int, array<int, array{0: int, 1: int}>>
     */
    protected function scan($stream, ?int $stopAt, ?int $size): array
    {
        $runs = [];
        $buffer = '';
        $bufferStart = 0;

        $currentDot = null;
        $currentStart = 0;
        $currentEnd = 0;

        $lines = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($stopAt ?: $size ?: 0);
        $bar->start();

        $flush = function () use (&$runs, &$currentDot, &$currentStart, &$currentEnd) {
            if ($currentDot === null) {
                return;
            }

            $existing = $runs[$currentDot] ?? [];
            $last = $existing ? $existing[count($existing) - 1] : null;

            // A DOT that reappears later in the file gets a second range rather
            // than one range swallowing everything in between.
            if ($last && $currentStart === $last[0] + $last[1]) {
                $runs[$currentDot][count($existing) - 1][1] += $currentEnd - $currentStart;
            } else {
                $runs[$currentDot][] = [$currentStart, $currentEnd - $currentStart];
            }
        };

        while (! feof($stream)) {
            $chunk = fread($stream, self::CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
            $cursor = 0;

            while (($newline = strpos($buffer, "\n", $cursor)) !== false) {
                $lineStart = $bufferStart + $cursor;
                $lineEnd = $bufferStart + $newline + 1;
                $dot = $this->dotFrom(substr($buffer, $cursor, $newline - $cursor));

                $cursor = $newline + 1;
                $lines++;

                if ($dot === null) {
                    $skipped++;

                    continue;
                }

                if ($dot !== $currentDot) {
                    $flush();
                    $currentDot = $dot;
                    $currentStart = $lineStart;
                }

                $currentEnd = $lineEnd;
            }

            $buffer = substr($buffer, $cursor);
            $bufferStart += $cursor;

            $bar->setProgress(min($bar->getMaxSteps(), $bufferStart));

            if ($stopAt !== null && $bufferStart >= $stopAt) {
                break;
            }
        }

        // The tail, when the export does not end in a newline.
        if ($buffer !== '' && $stopAt === null) {
            $dot = $this->dotFrom($buffer);

            if ($dot !== null) {
                if ($dot !== $currentDot) {
                    $flush();
                    $currentDot = $dot;
                    $currentStart = $bufferStart;
                }

                $currentEnd = $bufferStart + strlen($buffer);
                $lines++;
            }
        }

        $flush();
        $bar->finish();
        $this->newLine(2);

        $this->line(number_format($lines).' lines, '.number_format(count($runs)).' DOT numbers'
            .($skipped ? ', '.number_format($skipped).' unreadable lines skipped' : ''));

        return $runs;
    }

    /**
     * `{"DOT":"264184",...` — the first field of every record.
     */
    protected function dotFrom(string $line): ?int
    {
        $start = strpos($line, '"DOT":"');

        if ($start === false) {
            return null;
        }

        $start += 7;
        $end = strpos($line, '"', $start);

        if ($end === false) {
            return null;
        }

        $dot = substr($line, $start, $end - $start);

        return ctype_digit($dot) ? (int) $dot : null;
    }

    /**
     * Sorted, fixed-width and written aside then moved into place, so a rebuild
     * never leaves a half-written index for a request to binary-search.
     *
     * @param  array<int, array<int, array{0: int, 1: int}>>  $runs
     */
    protected function write(string $path, array $runs, ?int $size, ?string $etag): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        ksort($runs, SORT_NUMERIC);

        $entries = 0;
        $body = '';

        foreach ($runs as $dot => $ranges) {
            foreach ($ranges as [$offset, $length]) {
                $body .= pack('JJN', $dot, $offset, $length);
                $entries++;
            }
        }

        $header = self::MAGIC
            .pack('NNJJ', self::VERSION, $entries, $size ?? 0, time())
            .str_pad(substr((string) $etag, 0, 64), 64, "\0");

        $header = str_pad($header, self::HEADER_BYTES, "\0");

        $temporary = $path.'.tmp';

        file_put_contents($temporary, $header.$body);
        rename($temporary, $path);

        $this->info('Wrote '.number_format($entries).' index entries to '.$path
            .' ('.$this->humanBytes(filesize($path)).')');
    }

    /**
     * @return array{0: resource|null, 1: int|null, 2: string|null}
     */
    protected function openLocal(string $path): array
    {
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return [null, null, null];
        }

        return [fopen($path, 'rb'), filesize($path) ?: null, 'local:'.filemtime($path)];
    }

    /**
     * @return array{0: resource|null, 1: int|null, 2: string|null}
     */
    protected function openRemote(): array
    {
        $key = config('carriers.change_log.key');
        $url = config('carriers.change_log.url');

        try {
            if ($url) {
                $stream = fopen($url, 'rb');

                return [$stream ?: null, null, null];
            }

            $disk = Storage::disk(config('carriers.change_log.disk', 's3'));

            $metadata = $disk->getClient()->headObject([
                'Bucket' => config('filesystems.disks.'.config('carriers.change_log.disk', 's3').'.bucket'),
                'Key' => $key,
            ]);

            return [
                $disk->readStream($key),
                (int) $metadata['ContentLength'],
                trim((string) $metadata['ETag'], '"'),
            ];
        } catch (\Throwable $e) {
            $this->error('Could not open the export: '.$e->getMessage());

            return [null, null, null];
        }
    }

    /**
     * An index built from this exact export is not worth building twice.
     */
    protected function isCurrent(string $path, ?string $etag): bool
    {
        if (! $etag || ! is_readable($path)) {
            return false;
        }

        $header = file_get_contents($path, false, null, 0, self::HEADER_BYTES);

        if ($header === false || substr($header, 0, 8) !== self::MAGIC) {
            return false;
        }

        return rtrim(substr($header, 32, 64), "\0") === $etag;
    }

    protected function humanBytes(int|float $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}

<?php

namespace App\Services;

use App\Models\SubscriptionInvoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * DollarTraq's own invoice PDF.
 *
 * Stripe hosts a copy of every invoice and we link to it, but the file the
 * customer downloads from the billing screen is this one: the product's
 * letterhead, the plan named the way the pricing table names it, and the
 * service period spelled out. The numbers are still Stripe's — they are read
 * off the mirrored invoice row, never recomputed here.
 */
class InvoicePdfService
{
    /**
     * Render an invoice and hand back the raw PDF bytes.
     */
    public function render(SubscriptionInvoice $invoice): string
    {
        $html = View::make('invoices.subscription', $this->viewData($invoice))->render();

        $options = new Options;

        // Everything the page needs is inlined — the logo as a data URI, the
        // CSS in a <style> block. Leaving remote fetching off means a render
        // cannot hang on a network call or quietly drop the wordmark.
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        $options->set('dpi', 96);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(SubscriptionInvoice $invoice): array
    {
        $currency = strtolower($invoice->currency ?: 'usd');
        $symbol = config('billing.invoice.currency_symbols.'.$currency, '');

        // Resolved lazily and cached on the row — the download is the only
        // thing that needs to know which card paid.
        $card = app(BillingService::class)->resolveInvoiceCard($invoice);

        return [
            'invoice' => $invoice,
            'company' => $invoice->company,
            'issuer' => config('billing.issuer'),
            'brand' => config('billing.brand'),
            'notes' => config('billing.invoice.notes'),
            'footer' => config('billing.invoice.footer'),

            'logo' => $this->logoDataUri(),

            'lines' => $invoice->lines ?: [[
                // An invoice with no mirrored lines still has to describe what
                // was bought, and the plan and total are always known.
                'description' => trim(($invoice->planName() ?: 'Subscription').' plan'),
                'quantity' => 1,
                'amount_cents' => $invoice->total_cents,
                'proration' => false,
                'period_starts_at' => $invoice->period_starts_at,
                'period_ends_at' => $invoice->period_ends_at,
            ]],

            'card' => $card,

            'status' => $this->statusLabel($invoice),

            // A closure rather than pre-formatted strings, so the template can
            // format the totals and every line with one rule.
            'money' => fn (?int $cents) => $symbol.number_format(((int) $cents) / 100, 2),

            /*
            | Dates reach the template two ways. The invoice's own columns are
            | cast to Carbon; the per-line periods live inside the `lines` JSON
            | column, so they come back out as ISO strings. This takes either.
            */
            'date' => fn ($value) => $this->formatDate($value),

            'currencyCode' => strtoupper($currency),
        ];
    }

    private function formatDate(mixed $value): string
    {
        if (! $value) {
            return '—';
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('M j, Y');
        }

        try {
            return Carbon::parse((string) $value)->format('M j, Y');
        } catch (\Throwable) {
            // An unparseable date is not worth failing a download over.
            return '—';
        }
    }

    /**
     * The wordmark, inlined. Returns null when the file is missing rather than
     * failing the download — an invoice without a logo is still a valid
     * invoice, and the header falls back to the wordmark set in type.
     */
    private function logoDataUri(): ?string
    {
        $path = config('billing.brand.logo');

        if (! $path || ! is_file($path) || ! is_readable($path)) {
            Log::warning('Invoice logo is missing', ['path' => $path]);

            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function statusLabel(SubscriptionInvoice $invoice): array
    {
        if ($invoice->isPaid()) {
            return ['label' => 'Paid', 'tone' => 'paid'];
        }

        return match ($invoice->status) {
            SubscriptionInvoice::STATUS_OPEN => $invoice->isOverdue()
                ? ['label' => 'Overdue', 'tone' => 'overdue']
                : ['label' => 'Due', 'tone' => 'due'],
            SubscriptionInvoice::STATUS_VOID => ['label' => 'Void', 'tone' => 'void'],
            SubscriptionInvoice::STATUS_UNCOLLECTIBLE => ['label' => 'Uncollectible', 'tone' => 'overdue'],
            default => ['label' => 'Draft', 'tone' => 'void'],
        };
    }
}

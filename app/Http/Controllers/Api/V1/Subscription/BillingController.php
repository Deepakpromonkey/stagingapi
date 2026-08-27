<?php

namespace App\Http\Controllers\Api\V1\Subscription;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Billing\CancelSubscriptionRequest;
use App\Http\Requests\Billing\EmailInvoiceRequest;
use App\Http\Resources\SubscriptionInvoiceResource;
use App\Http\Resources\SubscriptionResource;
use App\Services\BillingService;
use App\Services\InvoicePdfService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything after the plan is bought: what the company is paying, what it has
 * been invoiced, the DollarTraq-branded PDF of any of those invoices, and
 * cancelling.
 *
 * Buying a plan is SubscriptionController's job; the two share
 * App\Services\SubscriptionService so neither can drift from the other on what
 * "subscribed" means.
 */
class BillingController extends BaseController
{
    /** Invoices per page in the history list. */
    private const PER_PAGE = 12;

    public function __construct(
        protected BillingService $billingService,
        protected InvoicePdfService $invoicePdfService,
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * The billing screen's opening state — plan, status, card, next charge and
     * this period's usage in one request.
     */
    public function overview()
    {
        $company = auth()->user()->company;

        $overview = $this->billingService->overview($company);
        $subscription = $overview['subscription'];

        return $this->success([
            'has_subscription' => (bool) $subscription,
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,

            'load_usage' => $overview['load_usage'],
            'payment_method' => $overview['payment_method'],
            'upcoming_invoice' => $overview['upcoming_invoice'],

            'billing_email' => $overview['billing_email'],
            'has_billing_account' => $overview['has_billing_account'],

            'can_cancel' => $overview['can_cancel'],
            'can_resume' => $overview['can_resume'],
            'allow_immediate_cancel' => $overview['allow_immediate_cancel'],
            'cancellation_reasons' => $overview['cancellation_reasons'],

            // So the screen can offer an upgrade without a second request.
            'plans' => $this->subscriptionService->plans($company),
        ]);
    }

    /** The invoice history, newest first. */
    public function invoices(Request $request)
    {
        $company = auth()->user()->company;

        $perPage = (int) $request->integer('per_page', self::PER_PAGE);
        $perPage = max(1, min(50, $perPage));

        $invoices = $this->billingService->invoices($company, $perPage);

        return $this->success([
            'invoices' => SubscriptionInvoiceResource::collection($invoices),
            'pagination' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /** One invoice, in full. */
    public function invoice(string $invoice)
    {
        $company = auth()->user()->company;

        return $this->success([
            'invoice' => new SubscriptionInvoiceResource(
                $this->billingService->findInvoice($company, $invoice)
            ),
        ]);
    }

    /**
     * The DollarTraq-branded PDF of an invoice.
     *
     * Ours, not Stripe's: Stripe's copy stays linked from the row
     * (`stripe_pdf_url`) for anyone who wants the provider's own record, but
     * the download is the one carrying our letterhead.
     */
    public function downloadInvoice(string $invoice)
    {
        $company = auth()->user()->company;

        $record = $this->billingService->findInvoice($company, $invoice);

        $pdf = $this->invoicePdfService->render($record);

        // A plain response rather than streamDownload: the PDF is already in
        // memory, and this way Content-Length is set, which is what lets the
        // browser show real download progress.
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$record->fileName().'"',
            'Content-Length' => (string) strlen($pdf),

            // Invoices are immutable once paid, but a re-download after a card
            // is resolved should not serve a stale body from a proxy.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Email an invoice out — our branded copy, and Stripe's own record of the
     * same charge from their side. See config/billing.php.
     */
    public function emailInvoice(EmailInvoiceRequest $request, string $invoice)
    {
        $company = auth()->user()->company;

        $record = $this->billingService->findInvoice($company, $invoice);

        $result = $this->billingService->emailInvoice(
            $record,
            $request->validated()['email'] ?? null
        );

        // Both channels are attempted; the message says what actually went, so
        // "sent" never overstates it.
        $message = $result['branded'] && $result['stripe']
            ? 'Invoice sent to '.$result['recipient'].', with a copy from Stripe.'
            : ($result['branded']
                ? 'Invoice sent to '.$result['recipient'].'.'
                : 'Stripe is sending its copy of this invoice to '.$result['recipient'].'.');

        return $this->success($result, $message);
    }

    /**
     * The invoice history as a CSV, for whoever closes the books.
     *
     * One row per invoice with the figures already in whole currency units, so
     * it drops straight into a spreadsheet without anyone dividing by 100.
     * Streamed rather than built in memory, matching CarrierExportController.
     */
    public function exportInvoices()
    {
        $company = auth()->user()->company;

        // The whole history, not a page of it — an export that silently stopped
        // at twelve rows would be worse than no export.
        $invoices = $this->billingService->invoices($company, 1000)->items();

        $columns = [
            'Invoice number',
            'Status',
            'Invoice date',
            'Due date',
            'Paid date',
            'Service period start',
            'Service period end',
            'Plan',
            'Currency',
            'Subtotal',
            'Discount',
            'Tax',
            'Total',
            'Amount paid',
            'Amount due',
            'Card',
            'Stripe invoice ID',
        ];

        $callback = function () use ($invoices, $columns) {
            $file = fopen('php://output', 'w');

            fputcsv($file, $columns);

            foreach ($invoices as $invoice) {
                fputcsv($file, [
                    $invoice->number ?: $invoice->stripe_invoice_id,
                    $invoice->isOverdue() ? 'overdue' : $invoice->status,
                    optional($invoice->issued_at)->format('Y-m-d'),
                    optional($invoice->due_at)->format('Y-m-d'),
                    optional($invoice->paid_at)->format('Y-m-d'),
                    optional($invoice->period_starts_at)->format('Y-m-d'),
                    optional($invoice->period_ends_at)->format('Y-m-d'),
                    $invoice->planName(),
                    strtoupper($invoice->currency),
                    number_format($invoice->subtotal_cents / 100, 2, '.', ''),
                    number_format($invoice->discount_cents / 100, 2, '.', ''),
                    number_format($invoice->tax_cents / 100, 2, '.', ''),
                    number_format($invoice->total_cents / 100, 2, '.', ''),
                    number_format($invoice->amount_paid_cents / 100, 2, '.', ''),
                    number_format($invoice->amount_due_cents / 100, 2, '.', ''),
                    $invoice->card_last4 ? $invoice->card_brand.' '.$invoice->card_last4 : '',
                    $invoice->stripe_invoice_id,
                ]);
            }

            fclose($file);
        };

        $filename = 'DollarTraq-invoices-'.now()->format('Y-m-d').'.csv';

        return new StreamedResponse($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The spend report: totals, trend, and this period's usage. */
    public function report(Request $request)
    {
        $company = auth()->user()->company;

        $months = (int) $request->integer('months', 12);
        $months = max(3, min(24, $months));

        return $this->success(
            $this->billingService->report($company, $months)
        );
    }

    /**
     * Cancel — at the end of the paid period by default, immediately if the
     * customer asks for that and the account allows it.
     */
    public function cancel(CancelSubscriptionRequest $request)
    {
        $data = $request->validated();

        $subscription = $this->billingService->cancel(
            $request->user()->company,
            (bool) ($data['immediately'] ?? false),
            $data['reason'] ?? null,
            $data['comment'] ?? null
        );

        $message = $subscription->cancel_at_period_end && $subscription->current_period_ends_at
            ? 'Your subscription will end on '
                .$subscription->current_period_ends_at->format('M j, Y')
                .'. You keep full access until then.'
            : 'Your subscription has been cancelled.';

        return $this->success([
            'subscription' => new SubscriptionResource($subscription),
        ], $message);
    }

    /** Undo a pending cancellation, while the paid period is still running. */
    public function resume()
    {
        $subscription = $this->billingService->resume(auth()->user()->company);

        return $this->success([
            'subscription' => new SubscriptionResource($subscription),
        ], 'Your subscription will continue. Nothing else changes.');
    }
}

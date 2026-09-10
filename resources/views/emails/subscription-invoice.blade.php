{{--
    The email the branded invoice PDF arrives on.

    Table layout and inline styles throughout: this has to survive Outlook and
    Gmail, neither of which can be relied on for flexbox or a <style> block.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->isPaid() ? 'Receipt' : 'Invoice' }} {{ $reference }}</title>
</head>
<body style="margin:0; padding:24px; background:#f5f7fb; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.6; color:{{ $brand['ink'] }};">

<table role="presentation" cellpadding="0" cellspacing="0" style="max-width:600px; margin:0 auto; width:100%; background:#ffffff; border-radius:10px; border-collapse:separate; overflow:hidden;">

    <tr>
        <td style="height:4px; background:{{ $brand['primary'] }}; font-size:0; line-height:0;">&nbsp;</td>
    </tr>

    <tr>
        <td style="padding:28px 28px 0;">
            <div style="font-size:20px; font-weight:bold; letter-spacing:-0.4px; color:{{ $brand['primary'] }};">
                {{ $issuer['name'] }}
            </div>
            <div style="font-size:10px; font-weight:bold; letter-spacing:1.4px; text-transform:uppercase; color:{{ $brand['muted'] }}; margin-top:4px;">
                {{ $issuer['tagline'] }}
            </div>
        </td>
    </tr>

    <tr>
        <td style="padding:22px 28px 0;">

            <p style="margin:0 0 14px;">Hi {{ $company->company_name }},</p>

            @if ($invoice->isPaid())
                <p style="margin:0 0 14px;">
                    Thanks — your {{ $invoice->planName() ?: 'subscription' }} payment came through.
                    The invoice is attached as a PDF for your records.
                </p>
            @else
                <p style="margin:0 0 14px;">
                    Here is your {{ $invoice->planName() ?: 'subscription' }} invoice, attached as a
                    PDF. It is settled automatically against the payment method on your account —
                    there is nothing you need to do.
                </p>
            @endif

            {{-- The figures at a glance, so the mail is useful without opening
                 the attachment. --}}
            <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; background:{{ $brand['wash'] }}; border:1px solid {{ $brand['hairline'] }}; border-radius:8px; border-collapse:collapse; margin:0 0 18px;">
                <tr>
                    <td style="padding:14px 16px 4px; color:{{ $brand['muted'] }}; font-size:12px;">Invoice</td>
                    <td style="padding:14px 16px 4px; text-align:right; font-weight:bold;">{{ $reference }}</td>
                </tr>
                <tr>
                    <td style="padding:4px 16px; color:{{ $brand['muted'] }}; font-size:12px;">
                        {{ $invoice->isPaid() ? 'Paid on' : 'Invoice date' }}
                    </td>
                    <td style="padding:4px 16px; text-align:right; font-weight:bold;">
                        {{ optional($invoice->isPaid() ? $invoice->paid_at : $invoice->issued_at)->format('M j, Y') ?? '—' }}
                    </td>
                </tr>
                @if ($invoice->period_starts_at && $invoice->period_ends_at)
                    <tr>
                        <td style="padding:4px 16px; color:{{ $brand['muted'] }}; font-size:12px;">Service period</td>
                        <td style="padding:4px 16px; text-align:right; font-weight:bold;">
                            {{ $invoice->period_starts_at->format('M j') }} – {{ $invoice->period_ends_at->format('M j, Y') }}
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:10px 16px 14px; border-top:1px solid {{ $brand['hairline'] }}; font-weight:bold;">
                        {{ $invoice->isPaid() ? 'Amount paid' : 'Amount due' }}
                    </td>
                    <td style="padding:10px 16px 14px; border-top:1px solid {{ $brand['hairline'] }}; text-align:right; font-weight:bold; font-size:17px;">
                        {{ config('billing.invoice.currency_symbols.'.strtolower($invoice->currency), '') }}{{ number_format(($invoice->isPaid() ? $invoice->amount_paid_cents : $invoice->amount_due_cents) / 100, 2) }}
                        {{ strtoupper($invoice->currency) }}
                    </td>
                </tr>
            </table>

            @if ($invoice->hosted_invoice_url)
                <p style="margin:0 0 18px;">
                    <a href="{{ $invoice->hosted_invoice_url }}"
                       style="display:inline-block; background:{{ $brand['primary'] }}; color:#ffffff; text-decoration:none; font-size:13px; font-weight:bold; padding:11px 22px; border-radius:999px;">
                        View invoice online
                    </a>
                </p>
            @endif

            <p style="margin:0 0 14px; color:{{ $brand['muted'] }}; font-size:12.5px;">
                You can also see every invoice, your plan, and your usage on the billing page in
                DollarTraq.
            </p>

        </td>
    </tr>

    <tr>
        <td style="padding:8px 28px 26px;">
            <div style="border-top:1px solid {{ $brand['hairline'] }}; padding-top:14px; color:{{ $brand['muted'] }}; font-size:11.5px;">
                {{ config('billing.invoice.footer') }}
                <br><br>
                {{ $issuer['legal_name'] ?: $issuer['name'] }}
                @if (! empty($issuer['website'])) · {{ $issuer['website'] }} @endif
            </div>
        </td>
    </tr>

</table>

</body>
</html>

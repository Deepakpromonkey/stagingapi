{{--
    The invoice the customer downloads.

    Rendered by Dompdf, which is a long way short of a browser: no flexbox, no
    grid, no CSS variables, and floats behave unpredictably across page breaks.
    The layout is therefore built out of tables and inline-ish CSS on purpose —
    it is not legacy markup, and rewriting it into modern CSS will silently
    collapse the columns.

    Every figure comes off the mirrored invoice row, which came from Stripe.
    Nothing is recalculated here.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number ?: $invoice->stripe_invoice_id }}</title>

    <style>
        @page { margin: 34px 40px 70px 40px; }

        body {
            margin: 0;
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.5;
            color: {{ $brand['ink'] }};
        }

        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 0; vertical-align: top; }

        .muted { color: {{ $brand['muted'] }}; }
        .right { text-align: right; }

        .eyebrow {
            font-size: 7.5px;
            font-weight: bold;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: {{ $brand['muted'] }};
        }

        /* ── Letterhead ─────────────────────────────────────────────── */

        .brand-rule {
            height: 3px;
            background: {{ $brand['primary'] }};
            font-size: 0;
            line-height: 0;
        }

        .wordmark {
            font-size: 23px;
            font-weight: bold;
            letter-spacing: -0.6px;
            color: {{ $brand['primary'] }};
        }

        .doc-title {
            font-size: 25px;
            font-weight: bold;
            letter-spacing: -0.5px;
            margin: 0;
        }

        /* ── Status pill ─────────────────────────────────────────────
           Dompdf ignores border-radius on inline elements, so the pill is a
           one-cell table with padding instead. */

        .pill td {
            padding: 3px 9px;
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            border-radius: 3px;
        }
        .pill-paid td     { background: #E7F7EE; color: #0F7A45; }
        .pill-due td      { background: #FFF4E0; color: #9A5B00; }
        .pill-overdue td  { background: #FDECEC; color: #B42318; }
        .pill-void td     { background: #F1F3F7; color: {{ $brand['muted'] }}; }

        /* ── Blocks ─────────────────────────────────────────────────── */

        .party-name { font-size: 11.5px; font-weight: bold; }

        .meta-strip {
            background: {{ $brand['wash'] }};
            border: 1px solid {{ $brand['hairline'] }};
            border-radius: 5px;
        }
        .meta-strip td { padding: 9px 12px; }
        .meta-value { font-size: 10.5px; font-weight: bold; }

        /* ── Line items ─────────────────────────────────────────────── */

        .items th {
            font-size: 7.5px;
            font-weight: bold;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            color: {{ $brand['muted'] }};
            border-bottom: 1.5px solid {{ $brand['ink'] }};
            padding: 0 0 6px;
            text-align: left;
        }
        .items td {
            padding: 9px 0;
            border-bottom: 1px solid {{ $brand['hairline'] }};
        }
        .item-name { font-size: 10.5px; font-weight: bold; }
        .item-sub { font-size: 9px; color: {{ $brand['muted'] }}; }

        .totals td { padding: 4px 0; }
        .grand td {
            border-top: 1.5px solid {{ $brand['ink'] }};
            padding-top: 8px;
            font-size: 13px;
            font-weight: bold;
        }

        .callout {
            background: {{ $brand['wash'] }};
            border-left: 3px solid {{ $brand['primary'] }};
            border-radius: 3px;
        }
        .callout td { padding: 9px 12px; font-size: 9.5px; }

        /* Repeats on every page, which is what @page's bottom margin is for. */
        .footer {
            position: fixed;
            bottom: -46px;
            left: 0;
            right: 0;
            border-top: 1px solid {{ $brand['hairline'] }};
            padding-top: 7px;
            font-size: 8.5px;
            color: {{ $brand['muted'] }};
        }
    </style>
</head>
<body>

    {{-- ── Letterhead ─────────────────────────────────────────────── --}}

    <table>
        <tr>
            <td style="width: 55%;">
                {{-- The wordmark image already carries the tagline, so it is
                     only set in type when the image is missing. --}}
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $issuer['name'] }}" width="{{ $brand['logo_width_px'] }}">
                @else
                    <div class="wordmark">{{ $issuer['name'] }}</div>

                    @if (! empty($issuer['tagline']))
                        <div class="eyebrow" style="margin-top: 5px;">{{ $issuer['tagline'] }}</div>
                    @endif
                @endif
            </td>

            <td class="right">
                <h1 class="doc-title">Invoice</h1>

                <div class="muted" style="font-size: 10.5px; margin-top: 2px;">
                    {{ $invoice->number ?: $invoice->stripe_invoice_id }}
                </div>

                {{-- Right-aligned by nesting: a floated table breaks across
                     page boundaries in Dompdf. --}}
                <table style="margin-top: 7px;">
                    <tr>
                        <td></td>
                        <td style="width: 1%;">
                            <table class="pill pill-{{ $status['tone'] }}">
                                <tr><td>{{ $status['label'] }}</td></tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="brand-rule" style="margin: 14px 0 20px;">&nbsp;</div>

    {{-- ── From / Bill to ─────────────────────────────────────────── --}}

    <table style="margin-bottom: 18px;">
        <tr>
            <td style="width: 50%; padding-right: 22px;">
                <div class="eyebrow" style="margin-bottom: 5px;">From</div>

                <div class="party-name">{{ $issuer['legal_name'] ?: $issuer['name'] }}</div>

                <div class="muted">
                    @if (! empty($issuer['address_line1'])){{ $issuer['address_line1'] }}<br>@endif
                    @if (! empty($issuer['address_line2'])){{ $issuer['address_line2'] }}<br>@endif
                    @if (! empty($issuer['city']) || ! empty($issuer['state']) || ! empty($issuer['zip']))
                        {{ collect([$issuer['city'], $issuer['state']])->filter()->implode(', ') }} {{ $issuer['zip'] }}<br>
                    @endif
                    @if (! empty($issuer['country'])){{ $issuer['country'] }}<br>@endif

                    @if (! empty($issuer['email'])){{ $issuer['email'] }}<br>@endif
                    @if (! empty($issuer['phone'])){{ $issuer['phone'] }}<br>@endif
                    @if (! empty($issuer['website'])){{ $issuer['website'] }}@endif
                </div>

                @if (! empty($issuer['tax_id']))
                    <div class="muted" style="margin-top: 4px;">
                        {{ $issuer['tax_id_label'] }} {{ $issuer['tax_id'] }}
                    </div>
                @endif
            </td>

            <td style="width: 50%;">
                <div class="eyebrow" style="margin-bottom: 5px;">Billed to</div>

                <div class="party-name">{{ $company->company_name }}</div>

                <div class="muted">
                    @if ($company->address){{ $company->address }}<br>@endif
                    @if ($company->city || $company->state || $company->zip_code)
                        {{ collect([$company->city, $company->state])->filter()->implode(', ') }} {{ $company->zip_code }}<br>
                    @endif
                    @if ($company->country){{ $company->country }}<br>@endif
                    @if ($company->company_email){{ $company->company_email }}<br>@endif
                    @if ($company->company_phone){{ $company->company_phone }}@endif
                </div>

                @if ($company->dot_number)
                    <div class="muted" style="margin-top: 4px;">USDOT {{ $company->dot_number }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ── Dates and plan ─────────────────────────────────────────── --}}

    <table class="meta-strip" style="margin-bottom: 20px;">
        <tr>
            <td style="width: 25%;">
                <div class="eyebrow">Invoice date</div>
                <div class="meta-value">{{ $date($invoice->issued_at) }}</div>
            </td>
            <td style="width: 25%;">
                <div class="eyebrow">{{ $invoice->isPaid() ? 'Paid on' : 'Due' }}</div>
                <div class="meta-value">
                    {{ $date($invoice->isPaid() ? $invoice->paid_at : $invoice->due_at) }}
                </div>
            </td>
            <td style="width: 25%;">
                <div class="eyebrow">Plan</div>
                <div class="meta-value">{{ $invoice->planName() ?: '—' }}</div>
            </td>
            <td style="width: 25%;">
                <div class="eyebrow">Amount {{ $invoice->isPaid() ? 'paid' : 'due' }}</div>
                <div class="meta-value">
                    {{ $money($invoice->isPaid() ? $invoice->amount_paid_cents : $invoice->amount_due_cents) }}
                    {{ $currencyCode }}
                </div>
            </td>
        </tr>
    </table>

    {{-- ── Line items ─────────────────────────────────────────────── --}}

    <table class="items">
        <thead>
            <tr>
                <th style="width: 58%;">Description</th>
                <th style="width: 10%;" class="right">Qty</th>
                <th style="width: 16%;" class="right">Unit price</th>
                <th style="width: 16%;" class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>
                        <div class="item-name">{{ $line['description'] }}</div>

                        @if (! empty($line['period_starts_at']) && ! empty($line['period_ends_at']))
                            <div class="item-sub">
                                Service period {{ $date($line['period_starts_at']) }} –
                                {{ $date($line['period_ends_at']) }}
                            </div>
                        @endif

                        @if (! empty($line['proration']))
                            <div class="item-sub">Prorated for a mid-period change</div>
                        @endif
                    </td>

                    <td class="right">{{ $line['quantity'] ?? 1 }}</td>

                    <td class="right">
                        {{ $money($line['unit_amount_cents'] ?? $line['amount_cents']) }}
                    </td>

                    <td class="right" style="font-weight: bold;">
                        {{ $money($line['amount_cents']) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ── Totals ─────────────────────────────────────────────────── --}}

    <table style="margin-top: 14px;">
        <tr>
            <td style="width: 56%;"></td>
            <td>
                <table class="totals">
                    <tr>
                        <td class="muted">Subtotal</td>
                        <td class="right">{{ $money($invoice->subtotal_cents) }}</td>
                    </tr>

                    @if ($invoice->discount_cents > 0)
                        <tr>
                            <td class="muted">Discount</td>
                            <td class="right">-{{ $money($invoice->discount_cents) }}</td>
                        </tr>
                    @endif

                    @if ($invoice->tax_cents > 0)
                        <tr>
                            <td class="muted">Tax</td>
                            <td class="right">{{ $money($invoice->tax_cents) }}</td>
                        </tr>
                    @endif

                    <tr class="grand">
                        <td>Total</td>
                        <td class="right">{{ $money($invoice->total_cents) }} {{ $currencyCode }}</td>
                    </tr>

                    @if ($invoice->isPaid())
                        <tr>
                            <td class="muted" style="padding-top: 6px;">Paid</td>
                            <td class="right" style="padding-top: 6px;">
                                -{{ $money($invoice->amount_paid_cents) }}
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Balance</td>
                            <td class="right" style="font-weight: bold;">{{ $money(0) }}</td>
                        </tr>
                    @elseif ($invoice->amount_due_cents > 0)
                        <tr>
                            <td style="font-weight: bold; padding-top: 6px;">Amount due</td>
                            <td class="right" style="font-weight: bold; padding-top: 6px;">
                                {{ $money($invoice->amount_due_cents) }}
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- ── How it was paid ────────────────────────────────────────── --}}

    @if ($invoice->isPaid())
        <table class="callout" style="margin-top: 20px;">
            <tr>
                <td>
                    <strong>Payment received</strong> &mdash;
                    {{ $money($invoice->amount_paid_cents) }} {{ $currencyCode }}
                    on {{ $date($invoice->paid_at) }}@if ($card && ! empty($card['last4'])), charged to {{ ucfirst($card['brand'] ?: 'card') }} ending {{ $card['last4'] }}@endif. Thank you.
                </td>
            </tr>
        </table>
    @elseif ($notes)
        <table class="callout" style="margin-top: 20px;">
            <tr><td>{{ $notes }}</td></tr>
        </table>
    @endif

    {{-- Stripe holds the provider-side record of the same invoice. Printing the
         reference means a support conversation can start from either copy. --}}
    <div class="muted" style="margin-top: 16px; font-size: 8.5px;">
        Stripe reference {{ $invoice->stripe_invoice_id }}
    </div>

    <div class="footer">
        <table>
            <tr>
                <td style="width: 75%;">{{ $footer }}</td>
                <td class="right">{{ $issuer['name'] }}</td>
            </tr>
        </table>
    </div>

</body>
</html>

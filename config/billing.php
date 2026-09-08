<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Who the invoice is from
    |--------------------------------------------------------------------------
    |
    | Printed in the header block of every invoice PDF we generate. Stripe has
    | its own copy of this in the dashboard's public business details, and the
    | two should say the same thing — the customer may well read both.
    |
    */

    'issuer' => [
        'name' => env('BILLING_ISSUER_NAME', 'DollarTraq'),
        'legal_name' => env('BILLING_ISSUER_LEGAL_NAME', 'DollarTraq Inc.'),
        'tagline' => env('BILLING_ISSUER_TAGLINE', 'Freight visibility simplified'),

        'address_line1' => env('BILLING_ISSUER_ADDRESS_LINE1'),
        'address_line2' => env('BILLING_ISSUER_ADDRESS_LINE2'),
        'city' => env('BILLING_ISSUER_CITY'),
        'state' => env('BILLING_ISSUER_STATE'),
        'zip' => env('BILLING_ISSUER_ZIP'),
        'country' => env('BILLING_ISSUER_COUNTRY', 'United States'),

        'email' => env('BILLING_ISSUER_EMAIL', 'billing@dollartraq.com'),
        'phone' => env('BILLING_ISSUER_PHONE'),
        'website' => env('BILLING_ISSUER_WEBSITE', 'https://dollartraq.com'),

        // Printed only when set — an EIN / VAT / GST number, whichever applies.
        'tax_id_label' => env('BILLING_ISSUER_TAX_ID_LABEL', 'EIN'),
        'tax_id' => env('BILLING_ISSUER_TAX_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | The palette the invoice PDF and the invoice email are drawn in. These are
    | the product's own colours, so a printed invoice reads as the same product
    | the customer signed into.
    |
    | `logo` is a filesystem path, not a URL: Dompdf fetches remote images over
    | HTTP at render time, which fails silently behind a firewall and leaves a
    | blank space where the wordmark should be. PNG, because Dompdf cannot
    | decode the WebP the frontend ships.
    |
    */

    'brand' => [
        'logo' => env('BILLING_BRAND_LOGO', resource_path('branding/dollartraq-logo.png')),
        'logo_width_px' => 168,

        'primary' => '#0052CC',
        'ink' => '#000B21',
        'accent' => '#16A34A',
        'muted' => '#64748B',
        'hairline' => '#E2E8F0',
        'wash' => '#F5F7FB',
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice copy
    |--------------------------------------------------------------------------
    */

    'invoice' => [
        // Shown under the totals. Subscriptions are charged to a card on file,
        // so the default says so rather than quoting payment terms that do not
        // apply.
        'notes' => env(
            'BILLING_INVOICE_NOTES',
            'This invoice is settled automatically against the payment method on file. No action is required.'
        ),

        'footer' => env(
            'BILLING_INVOICE_FOOTER',
            'Questions about this invoice? Email billing@dollartraq.com and quote the invoice number above.'
        ),

        // Every invoice is raised and paid in the same currency the plan is
        // priced in; this only decides the symbol the PDF prints.
        'currency_symbols' => [
            'usd' => '$',
            'cad' => 'CA$',
            'eur' => '€',
            'gbp' => '£',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Who emails the invoice
    |--------------------------------------------------------------------------
    |
    | Both sides can, and by default both do:
    |
    | `send_branded_email` mails our own PDF from this application when Stripe
    | reports a payment — that is the copy carrying DollarTraq's letterhead.
    |
    | `ask_stripe_to_email` additionally has Stripe send its own receipt for
    | the same charge. Stripe will only do that for an invoice it can email:
    | one billed to terms (collection_method=send_invoice) is sent outright,
    | while a subscription charged to a card on file gets a receipt, which
    | Stripe emits by writing the address onto the charge. Turn this off if
    | the account's Stripe email settings already cover it and customers are
    | receiving two.
    |
    */

    'send_branded_email' => (bool) env('BILLING_SEND_BRANDED_EMAIL', true),

    'ask_stripe_to_email' => (bool) env('BILLING_ASK_STRIPE_TO_EMAIL', true),

    /*
    |--------------------------------------------------------------------------
    | Cancellation
    |--------------------------------------------------------------------------
    |
    | A customer may cancel at any time. By default the subscription is left to
    | run to the end of the period already paid for — cancelling on the 2nd of
    | the month should not take away 28 days the customer has been charged
    | for, and Stripe issues no refund for the unused part either way.
    |
    | `allow_immediate` lets the customer end it there and then instead, losing
    | the remainder. Reasons offered are Stripe's own cancellation feedback
    | values, so they land in Stripe's churn reporting rather than only ours.
    |
    */

    'cancellation' => [
        'allow_immediate' => (bool) env('BILLING_ALLOW_IMMEDIATE_CANCEL', true),

        'reasons' => [
            'too_expensive' => 'Too expensive',
            'missing_features' => 'Missing features I need',
            'switched_service' => 'Switched to another service',
            'unused' => 'Not using it enough',
            'too_complex' => 'Too hard to use',
            'low_quality' => 'Not happy with the quality',
            'customer_service' => 'Unhappy with support',
            'other' => 'Another reason',
        ],
    ],

];

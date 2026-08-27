# Billing

What a subscribed company can see and do about its own money, and what has to
exist outside the codebase for it to work.

Buying a plan is the other half of this and lives in
`SubscriptionController` / `SubscriptionService`; everything below is what
happens after the first payment goes through.

```
Stripe charges the card
     -> invoice.paid / invoice.payment_succeeded webhook
     -> SubscriptionService::syncSubscriptionById()   (plan state)
     -> BillingService::syncInvoiceFromStripe()       (invoice mirror)
     -> SubscriptionInvoiceMail, branded PDF attached (once per invoice)

broker opens /billing
     -> GET /billing            plan, card, next charge, usage
     -> GET /billing/invoices   the mirror, topped up from Stripe
     -> GET /billing/report     totals + spend by month
     -> download  -> our PDF        (InvoicePdfService)
     -> email     -> our PDF + ask Stripe to send its copy
     -> export    -> the whole history as CSV
     -> cancel    -> Stripe, at period end by default
```

| Endpoint | What it answers |
|---|---|
| `GET /billing` | plan, status, card on file, next charge, load usage, cancel/resume affordances |
| `GET /billing/report?months=` | totals paid / outstanding / average, spend by month, plan, usage |
| `GET /billing/invoices?page=` | the invoice history, paginated |
| `GET /billing/invoices/export` | the whole history as a CSV (registered **before** `{invoice}`, or `export` is read as a reference) |
| `GET /billing/invoices/{uuid}` | one invoice in full |
| `GET /billing/invoices/{uuid}/download` | the DollarTraq-branded PDF |
| `POST /billing/invoices/{uuid}/email` | email it — our copy, and Stripe's |
| `POST /billing/subscription/cancel` | cancel, at period end by default |
| `POST /billing/subscription/resume` | clear a pending cancellation |

`{uuid}` is our own `subscription_invoices.uuid`; a Stripe invoice id is
accepted too, so a support conversation can start from either system's
reference.

---

## 1. Stripe is the source of truth

Nothing in `subscriptions` or `subscription_invoices` is ever written from our
own arithmetic. Every row is a mirror of a payload Stripe gave us, arriving
either on a webhook or on an explicit read. The mirror exists for three
reasons and no others:

- the billing report is one query instead of paging the Stripe API;
- an invoice stays readable after a plan is renamed or repriced, because the
  plan key and the line items are snapshotted on it;
- "email me this invoice" can be made idempotent, via `emailed_at`.

If the mirror and Stripe ever disagree, Stripe wins. `GET /billing/invoices`
and `GET /billing/report` both top the mirror up on read (throttled to one
Stripe call every two minutes per company), so a webhook that failed to deliver
self-heals the next time someone opens the page.

## 2. Stripe API versions

Stripe's 2025 API versions moved several fields this code reads. Both shapes
are handled, because which one arrives depends on the version the *account* is
pinned to, not on anything we control:

| What | Older versions | 2025+ |
|---|---|---|
| Invoice's subscription | `invoice.subscription` | `invoice.parent.subscription_details.subscription` |
| Invoice's payment | `invoice.charge` / `invoice.payment_intent` | `invoice.payments.data[].payment.*` |
| Invoice tax | `invoice.tax` | `invoice.total_taxes[].amount` |
| Line item price | `line.price` | `line.pricing.price_details` |
| Next charge preview | `GET /invoices/upcoming` | `POST /invoices/create_preview` |

Card details are deliberately **not** requested with `expand` on the invoice
list: the expandable field names differ between versions, and an invalid
`expand` is a hard 400 that would break the whole invoice list. Instead the
charge or payment intent id is stored, and the card is resolved on demand by
`BillingService::resolveInvoiceCard()` — only the PDF needs it — then cached
onto the row.

## 3. Webhook events to subscribe to

The endpoint is unchanged: `POST /api/v1/stripe/webhook`, authorised by the
signature rather than a token. It now acts on more events, and **each one has
to be enabled on the Stripe webhook endpoint** or the mirror only fills in from
the throttled read path:

```
checkout.session.completed
checkout.session.async_payment_succeeded
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
invoice.created
invoice.finalized
invoice.updated
invoice.paid
invoice.payment_succeeded
invoice.payment_failed
invoice.voided
invoice.marked_uncollectible
```

`invoice.paid` and `invoice.payment_succeeded` both fire for the same payment,
and Stripe replays either after a failed delivery. The branded email is sent
with `once: true`, which is what stops the customer receiving the same invoice
three times.

## 4. Two invoice emails, on purpose

| Copy | Sent by | Controlled by |
|---|---|---|
| DollarTraq letterhead, PDF attached | this application, `SubscriptionInvoiceMail` | `BILLING_SEND_BRANDED_EMAIL` |
| Stripe's own record | Stripe | `BILLING_ASK_STRIPE_TO_EMAIL` |

How Stripe is asked depends on how the invoice is collected, and there is no
single call that covers both:

- `collection_method=send_invoice` and still open → `POST /invoices/{id}/send_invoice`.
- `charge_automatically` (every self-serve subscription) → Stripe will not
  "send" it; writing `receipt_email` onto the charge or payment intent is what
  makes Stripe email a receipt.

**In the Stripe dashboard** this interacts with *Settings → Customer emails →
"Email customers about successful payments"*. If that is on, Stripe already
mails a receipt for every charge and `BILLING_ASK_STRIPE_TO_EMAIL=false` avoids
customers getting two from Stripe's side. Set the logo and brand colour under
*Settings → Branding* so Stripe's copy looks like ours.

## 5. Cancelling

`POST /billing/subscription/cancel` defaults to the end of the period the
customer has already paid for (`cancel_at_period_end=true`). Ending it there
and then is offered as a second option, gated on
`BILLING_ALLOW_IMMEDIATE_CANCEL`, and forfeits the remainder — Stripe refunds
nothing for the unused part either way, so early cancellation only costs the
customer access.

`past_due` still grants access (see `Subscription::ACCESS_STATUSES`), so a
single declined card does not lock a broker out mid-load while Stripe retries.

The reason, if the customer gives one, is passed through as Stripe's
`cancellation_details[feedback]`, so churn lands in Stripe's own reporting. The
accepted keys in `config('billing.cancellation.reasons')` are Stripe's enum
values verbatim — renaming a key silently drops the feedback, because
`BillingService::stripeFeedback()` only forwards values it recognises.

`POST /billing/subscription/resume` clears a pending cancellation. It only
works while the paid period is still running; after that Stripe has closed the
subscription and a new checkout is the only way back.

## 6. The invoice PDF

`InvoicePdfService` renders `resources/views/invoices/subscription.blade.php`
through Dompdf. Two things about that template are not stylistic choices:

- **It is built out of tables.** Dompdf has no flexbox and no grid, and floats
  behave unpredictably across page breaks. Rewriting the layout in modern CSS
  will collapse the columns.
- **The logo is inlined as a `data:` URI** from a filesystem path
  (`resources/branding/dollartraq-logo.png`), not fetched over HTTP. Remote
  fetching is disabled, so a URL would render as a blank space. PNG, because
  Dompdf cannot decode the WebP the frontend ships.

The core Helvetica font Dompdf uses has no glyph for U+2212 MINUS SIGN — use a
hyphen for negative amounts.

## 7. Environment

```dotenv
# Already needed for checkout
STRIPE_SECRET=sk_live_…
STRIPE_WEBHOOK_SECRET=whsec_…
STRIPE_PRICE_STANDARD=price_…
STRIPE_PRICE_PRO=price_…
FRONTEND_URL=https://broker.dollartraq.com

# Printed on the invoice PDF. Should match Stripe's public business details,
# because the customer may well read both copies.
BILLING_ISSUER_LEGAL_NAME="DollarTraq Inc."
BILLING_ISSUER_ADDRESS_LINE1=
BILLING_ISSUER_CITY=
BILLING_ISSUER_STATE=
BILLING_ISSUER_ZIP=
BILLING_ISSUER_EMAIL=billing@dollartraq.com
BILLING_ISSUER_PHONE=
BILLING_ISSUER_TAX_ID=            # printed only when set

# Who emails invoices — see section 4
BILLING_SEND_BRANDED_EMAIL=true
BILLING_ASK_STRIPE_TO_EMAIL=true

BILLING_ALLOW_IMMEDIATE_CANCEL=true
```

## 8. Deployment checklist

1. `composer install` — this adds `dompdf/dompdf`, which the PDF needs.
2. `php artisan migrate` — creates `subscription_invoices`.
3. Add the `invoice.*` events listed in section 3 to the Stripe webhook
   endpoint.
4. **Activate the billing portal** at *Stripe → Settings → Billing → Customer
   portal*. Until it is saved once, `POST /subscription/portal` fails with a
   Stripe 400, and the "Manage payment & tax details" and "Update card" buttons
   on `/billing` are the only way a customer can change their card.
5. Set the branding and customer-email options in section 4.
6. Fill in the `BILLING_ISSUER_*` values. They are optional in the sense that
   the PDF renders without them, but an invoice with no issuer address is not
   much of an invoice.

## 9. Access

Every `/billing` endpoint is gated on the `edit-company-profile-billing`
permission, the same one that guards changing the plan: an invoice states what
the company pays, which is not something every seat on a brokerage desk should
read. The frontend hides the menu item and blocks the route on the same
permission, but that is convenience — the API is what enforces it.

`/billing` is exempt from the frontend paywall redirect. Someone whose
subscription has lapsed is exactly who needs to reach their invoices and
resubscribe, and gating it would put the door on the inside.

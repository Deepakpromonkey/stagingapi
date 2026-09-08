# Stripe go-live checklist

Everything Stripe touches moves to **live** except **DT Pay**, which stays on
sandbox keys.

The split is already in the code and needs no changes:

| Area | Reads credentials from | After go-live |
|---|---|---|
| Subscriptions / Checkout — `app/Services/SubscriptionService.php` | `services.stripe.*` (`.env`) | **live** |
| Billing, invoices, portal — `app/Services/BillingService.php` | `services.stripe.*` (`.env`) | **live** |
| Webhook — `app/Http/Controllers/Api/V1/Subscription/StripeWebhookController.php` | `STRIPE_WEBHOOK_SECRET` | **live** |
| Carrier Connect Express — `app/Http/Controllers/Api/V1/Connect/CarrierConnectController.php` | `services.stripe.secret` | **live** |
| **DT Pay** — `app/Models/Payments/StripeModel.php` | hardcoded consts, `const mode = 'sandbox'` | **test — do not touch** |

DT Pay never reads `.env`. Swapping the env values below cannot move it, so no
guard is needed.

---

## 1. Read this first — the known breakage

Carrier Express accounts are created by **Connect** (live after this change) and
paid out by **DT Pay** (test). Stripe will not transfer from a test-mode API key
to a live-mode `acct_…`.

`DtPayTransactionsController::releasePayment()` (line ~211) does exactly that:

```php
list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials(); // TEST
$stripe->transfers->create(['destination' => $connect_request->stripe_express_account]);
```

Consequence, and there is no way to have both:

- **Carriers onboarded after go-live** get a live `acct_…`. DT Pay release will
  fail for them until DT Pay also goes live.
- **Carriers onboarded before go-live** hold a test `acct_…`. DT Pay release
  keeps working for them, but `POST /v1/carrier/stripe/verify` will 404 under
  the live key.

Decide per carrier in step 5. The permanent fix is moving DT Pay live too.

---

## 2. Build the live objects in the Stripe dashboard

Switch the dashboard to **live mode**, then:

1. **Activate the account.** Live Express account creation is blocked until the
   platform account is activated (business details, bank account, tax info).
2. **Enable Connect in live mode.** Settings → Connect: business profile,
   branding, payout schedule. Express onboarding 400s without it.
3. **Recreate the two prices.** Test price IDs do not exist in live.
   - Standard — USD 249.00 / month recurring
   - Pro — USD 499.00 / month recurring

   Amounts must match `config/subscriptions.php` (`amount` 249 / 499,
   `currency` usd, `interval` month) or the invoice PDFs disagree with Stripe.
   Enterprise has no price ID — it routes to sales.
4. **Create the live webhook endpoint.**

   URL: `https://<your-api-domain>/api/v1/stripe/webhook`

   Events (exactly what `StripeWebhookController::handle()` matches):

   ```
   checkout.session.completed
   checkout.session.async_payment_succeeded
   customer.subscription.created
   customer.subscription.updated
   customer.subscription.deleted
   invoice.paid
   invoice.payment_succeeded
   invoice.payment_failed
   invoice.created
   invoice.finalized
   invoice.updated
   invoice.voided
   invoice.marked_uncollectible
   ```

   Copy the endpoint's signing secret — that is `STRIPE_WEBHOOK_SECRET`, and it
   is different from the test endpoint's.

---

## 3. Environment variables

On the production server's `.env`:

```dotenv
# Platform Stripe — live
STRIPE_KEY=pk_live_…
STRIPE_SECRET=sk_live_…
STRIPE_WEBHOOK_SECRET=whsec_…          # from the LIVE endpoint, step 2.4
STRIPE_PRICE_STANDARD=price_…          # live price, step 2.3
STRIPE_PRICE_PRO=price_…               # live price, step 2.3

# Redirect targets. Must be the real https domain — Stripe rejects localhost
# return_url in live mode, and FRONTEND_URL defaults to http://localhost:5173.
FRONTEND_URL=https://app.dollartraq.com
APP_URL=https://api.dollartraq.com

APP_ENV=production
APP_DEBUG=false
```

Used by, for reference:

- `FRONTEND_URL` → `SubscriptionService::frontendUrl()` builds Checkout
  `success_url` / `cancel_url`, the billing portal `return_url`, and Connect's
  `refresh_url` / `return_url`.
- `SUBSCRIPTION_SUCCESS_PATH`, `SUBSCRIPTION_CANCEL_PATH`,
  `SUBSCRIPTION_PORTAL_RETURN_PATH` — defaults in `config/subscriptions.php` are
  fine unless the frontend routes differ.

**Do not add** any `DTPAY_*` or DT Pay Stripe keys. DT Pay's credentials live in
`StripeModel` consts and stay as they are.

Then, because Laravel caches config and `.env` edits are otherwise inert:

```bash
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan queue:restart
```

---

## 4. Clear the stale test-mode IDs

`SubscriptionService::resolveCustomer()` reuses `companies.stripe_customer_id`
verbatim when it is set. A test-mode `cus_…` sent to the live API 404s and the
customer can never check out. Same for subscriptions and invoices.

Back up first, then — **only if these rows are all test-mode data**:

```sql
-- Platform billing: force re-creation against live Stripe.
UPDATE companies SET stripe_customer_id = NULL;

DELETE FROM subscription_invoices;
DELETE FROM subscriptions;
```

**Leave `users.stripe_customer_id` alone.** That column is DT Pay's
(`2026_08_10_000008_dt_payments_add_stripe_customer_id_to_users_table.php`) and
stays test-mode.

If any company has a genuine paying subscription in test mode, recreate it in
live Checkout rather than editing IDs by hand — the webhook backfills
`subscriptions` and `subscription_invoices` on its own.

---

## 5. Carrier Express accounts — per-carrier decision

`carrier_connect_requests.stripe_express_account` holds test-mode `acct_…` IDs.

- **Carrier still awaiting a DT Pay release** → leave the row alone. DT Pay's
  test key can still transfer to it. Connect verify will fail for them; that is
  the lesser harm.
- **Carrier fully settled, or newly onboarding** → clear it so they re-onboard
  against live Connect:

  ```sql
  UPDATE carrier_connect_requests
     SET stripe_express_account = NULL,
         stripe_verified_at = NULL
   WHERE carrier_dot_number IN (…);
  ```

  Their future DT Pay releases will fail until DT Pay goes live.

Worth confirming while you are here: DT Pay's test key is
`sk_test_51TP2bME8lGA6s4DI…`. Check the **current** `STRIPE_SECRET` carries the
same `51TP2bME8lGA6s4DI` account fragment. If it does not, Connect and DT Pay
have been on two different Stripe accounts and those transfers never worked at
all — a separate bug to chase before go-live.

---

## 6. Verify after deploy

```bash
php artisan tinker --execute="dump(substr(config('services.stripe.secret'),0,8));"   # sk_live_
php artisan tinker --execute="dump(config('subscriptions.plans.standard.price_id'));" # live price_
php artisan tinker --execute="dump(config('app.frontend_url'));"                     # https, not localhost
php artisan tinker --execute="dump((new App\Models\Payments\StripeModel)->get_credentials()[1] ? 'dtpay:'.substr((new App\Models\Payments\StripeModel)->get_credentials()[1],0,8) : 'empty');"  # sk_test_ — must stay test
```

Then, end to end:

1. `GET /api/v1/subscription/plans` returns the live price IDs.
2. Real card through Checkout on Standard → subscription row created, invoice
   PDF emailed, Stripe live dashboard shows the charge.
3. Stripe dashboard → Webhooks → the live endpoint shows 200s, not 400
   signature failures.
4. Billing portal opens and returns to `FRONTEND_URL`.
5. A new carrier through `POST /v1/carrier/stripe/connect` gets a live
   onboarding link.
6. **DT Pay:** a guest payment still succeeds with a `4242…` test card. If a
   test card is declined, something leaked live keys into DT Pay — stop and
   check `StripeModel::mode`.

---

## 7. Rollback

Restore the previous `.env`, then `config:clear && config:cache`. Live customers
and subscriptions created in the meantime stay in live Stripe and will be
invisible to the test-mode app, so restore the DB backup from step 4 alongside
it.

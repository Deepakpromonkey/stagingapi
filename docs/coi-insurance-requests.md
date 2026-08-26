# Chasing a carrier's insurance agency

What happens when a broker presses **Raise request** on a carrier profile's
insurance card, and what has to exist outside the codebase for it to work.

The whole flow is one loop:

```
card -> POST /carrier-insurance-requests
     -> mail to the agency, Reply-To: insurance+{dot}-{token}@inbox…
     -> agency replies
     -> provider POSTs /webhooks/inbound-email
     -> reply stored, ExtractInsuranceExpiry queued
     -> Claude reads the expiry date out of the reply
     -> request status = success, date on the card, reply one click away
```

---

## 1. The mailbox

One real mailbox receives every reply. Nothing is provisioned per request —
the DOT and a per-request token ride in the sub-address, which is what
`CarrierInsuranceRequestService::matchRequest()` matches on:

```
insurance+1234567-k3f9x2p7q1m4h8s0d6b2n5v7@inbox.dollartraq.com
          ^^^^^^^ ^^^^^^^^^^^^^^^^^^^^^^^^
          DOT     reply_token
```

Set up:

1. Create `insurance@inbox.dollartraq.com` (or whatever `COI_INBOX_LOCAL_PART`
   and `COI_INBOX_DOMAIN` say) at the mail provider.
2. Make sure the provider **preserves plus-addressing** and delivers
   `insurance+anything@` to that mailbox. All four providers below do; a plain
   Google Workspace mailbox does too.
3. Point the mailbox's inbound route at
   `POST https://brokerapi.dollartraq.com/api/v1/webhooks/inbound-email`,
   carrying the shared secret either as the `X-Inbound-Secret` header or as
   `?secret=…` on the URL.

`InboundEmailPayload` recognises Postmark, Mailgun routes, SendGrid Inbound
Parse and SES-through-SNS by payload shape, so which one is used is a
deployment decision, not a code change.

| Provider | Where to point it | Secret |
|---|---|---|
| Postmark | Server -> Inbound webhook URL | `?secret=` on the URL |
| Mailgun | Receiving -> Routes -> forward to URL | `?secret=` on the URL |
| SendGrid | Inbound Parse -> Destination URL | `?secret=` on the URL |
| SES | Receipt rule -> SNS topic -> HTTPS subscription | `?secret=` on the URL |

**SES only:** set `COI_INBOUND_SNS_TOPIC_ARN` before subscribing. The endpoint
confirms an SNS subscription only for that ARN — an unknown topic is logged and
ignored, because auto-confirming whatever arrives is a request-forgery
primitive. The receipt rule must also include the mail itself (an S3 or SNS
action that populates `content`), or the notification arrives with no body to
read.

---

## 2. Environment

```dotenv
# The inbox and what the mail says it is from
COI_INBOX_LOCAL_PART=insurance
COI_INBOX_DOMAIN=inbox.dollartraq.com
COI_FROM_ADDRESS=insurance@dollartraq.com
COI_FROM_NAME="DollarTraq Team"

# The whole of the webhook's authorisation. Unset closes the endpoint.
COI_INBOUND_SECRET=

# SES only
COI_INBOUND_SNS_TOPIC_ARN=

# Reading the date out of the reply
ANTHROPIC_API_KEY=
COI_LLM_MODEL=claude-opus-5

# Neither the send nor the extraction may run inline — see config/coi_insurance.php
COI_QUEUE_CONNECTION=database
COI_QUEUE=default
```

A worker has to be draining `COI_QUEUE`, or requests sit at `pending` with the
mail unsent and replies sit unread.

---

## 3. Who gets the mail

`CoiContactResolver` wants the **producer's** address — the agency that issued
the certificate — not the carrier's. An ACORD 25 names them, and they are the
only party who can answer "what is it now".

The extraction JSON is whatever the OCR made of a scanned PDF and its key names
differ between certificates, so the resolver walks the structure looking for an
address rather than reading a fixed path, preferring one found under a path
mentioning producer / agent / agency / broker. Order:

1. Producer address on the newest COI extraction for that DOT (`recipient_source: ocr`)
2. Any other address on a recent extraction (`ocr`)
3. `carriers.email_address` from the census (`recipient_source: fmcsa`)
4. Nothing — the endpoint answers 422 and the card shows why

Which one was used is stored on the request, because chasing an agency and
chasing the carrier's own dispatch line are not the same act and the reply rate
is not the same either.

---

## 4. Status

| Status | Means |
|---|---|
| `pending` | Mail sent, waiting on the agency |
| `responded` | A reply arrived; the date has not been read out of it yet |
| `success` | An expiry date was extracted — it is on the request row |
| `failed` | The send failed, or the reply carried no usable date |
| `expired` | Nobody answered inside `COI_EXPIRE_AFTER_DAYS` |

`responded` is short-lived — it lasts as long as the queued extraction — which
is why the card polls in that state and not in `pending`.

Nothing moves a row backwards. A second reply on a resolved request is stored
but does not reopen it, so an agency's "let us know if you need anything else"
cannot knock a good answer back to pending.

`coi:expire-requests` runs daily at 04:15 and is the only thing that moves a row
off `pending`. Without it the card would say "Pending" forever on a mail an
agency ignored two months ago, *and* the resend cooldown would never let the
broker ask again — an open request short-circuits it.

---

## 5. Reading the date

`InsuranceExpiryExtractor` sends the reply body to Claude and asks for exactly
one line:

```
Insurance Expiry Date - 2026-04-30
```

Only that line is parsed. Two details are load-bearing:

- **The date must be ISO.** Carbon reads `04/05/2026` as the 4th of May and a
  US-written COI means the 5th of April; refusing anything but `Y-m-d`, and
  round-tripping it to reject overflow, is the only way that ambiguity cannot
  silently produce a wrong date.
- **`NOT FOUND` is an answer, not a failure.** A reply saying the policy was
  cancelled resolves the request as `failed` with the reason on the row —
  it does not retry, and it does not sit at pending.

The body sent is the provider's stripped reply where there is one. A thread
quoted eight replies deep usually contains an older, now-wrong expiry date, and
feeding both invites the model to pick the stale one.

Every reply is kept whole on `coi_insurance_responses`, along with what Claude
answered verbatim. The extracted date is a claim about an insurance policy that
a broker may book a load on, so it has to be checkable against its source
without asking anyone.

---

## 6. Endpoints

| Method | Path | Notes |
|---|---|---|
| `GET` | `/v1/carriers/{dot}/insurance-request` | The open or most recent request. What the card renders. |
| `POST` | `/v1/carrier-insurance-requests` | Raise one. Idempotent per company and DOT. |
| `GET` | `/v1/carrier-insurance-requests` | Everything the company has raised. `?status=` filters. |
| `GET` | `/v1/carrier-insurance-requests/responses/{uuid}` | The reply itself. |
| `POST` | `/v1/webhooks/inbound-email` | Public. Secret-authorised. |

Everything but the webhook is company-scoped: a colleague opening the same
carrier profile sees the request someone else raised, which is the point — two
people must not mail the same agency twice.

The webhook answers `200` to almost everything, including a mail it could not
match. A provider that does not get a prompt `200` redelivers, and redelivering
an unmatched mail helps nobody; the one thing it would achieve is a second
Claude call for a mail that did match.

---

## 7. Testing

### Unit — no database, no key, no mailbox

```bash
vendor/bin/phpunit tests/Unit/CoiInboundEmailPayloadTest.php
```

Covers the part most likely to break silently: the four inbound payload shapes,
address normalisation, and quoted-printable MIME.

> The **Feature** suite does not run on sqlite, and has not for a while — it is
> not this feature. Four migrations use MySQL-only SQL (`ALTER … MODIFY`,
> `SHOW INDEX`, `information_schema`) and `dt_pay_guest` creates a unique index
> named `dt_payments_row_id_unique`, which MySQL scopes per table and sqlite
> scopes per database. Point `phpunit.xml` at a MySQL test database, or fix
> those five, before writing feature tests here.

### The first thing worth checking

Everything downstream depends on the certificate on file actually carrying an
agency address. Check a real DOT before anything else:

```bash
php artisan tinker
>>> app(App\Services\Coi\CoiContactResolver::class)->resolve(1234567);
=> ["email" => "certs@someagency.com", "source" => "ocr"]
```

`source: fmcsa` means it fell back to the carrier's own census address and no
agency address was found in the OCR. `null` means the Raise button will answer
422 for that carrier.

### End to end, locally, with no mailbox at all

The Reply-To token is the whole routing mechanism, and it is printed in the log
mailer's output — so the agency's reply can be simulated with `curl`.

```dotenv
MAIL_MAILER=log
COI_QUEUE_CONNECTION=sync      # send and extraction run inline; no worker needed
COI_INBOUND_SECRET=local-test
COI_INBOX_DOMAIN=inbox.dollartraq.com
ANTHROPIC_API_KEY=sk-ant-...   # the extraction is a real call
```

**1. Raise it.** Press the button, or:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/carrier-insurance-requests \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"dot_number": 1234567}'
```

`$TOKEN` is `crm_auth_token` out of the browser's localStorage.

**2. Read the token back out of the log.** The mail is in
`storage/logs/laravel.log` — subject, body and the address that matters:

```bash
grep -o 'insurance+[0-9]*-[a-z0-9]*@[^ >]*' storage/logs/laravel.log | tail -1
```

**3. Reply as the agency.** Post a Postmark-shaped payload back at the webhook,
with that address as the recipient:

```bash
curl -X POST 'http://127.0.0.1:8000/api/v1/webhooks/inbound-email?secret=local-test' \
  -H 'Content-Type: application/json' \
  -d '{
    "FromFull": {"Email": "certs@someagency.com", "Name": "Jane Agent"},
    "ToFull": [{"Email": "insurance+1234567-PASTE_TOKEN@inbox.dollartraq.com"}],
    "Subject": "RE: Insurance details of the carrier ACME 1234567",
    "TextBody": "Hi, the auto liability policy runs through April 30, 2026. Cert attached.",
    "StrippedTextReply": "Hi, the auto liability policy runs through April 30, 2026."
  }'
```

**4. Check it landed.** The card should now read **Received** with
`Insurance expiry date — Apr 30, 2026`, and Track should open the reply. Or:

```bash
php artisan tinker
>>> App\Models\CoiInsuranceRequest::latest('id')->first(['status','insurance_expiry_date','last_error']);
```

### Cases worth walking

| Change to step 3 | Expect |
|---|---|
| `"TextBody": "That policy was cancelled last month."` | `failed`, reason on the row, no date — **not** a retry |
| Wrong or missing `?secret=` | `401`, nothing stored |
| Recipient with a token that matches nothing | `200`, "No matching request", logged |
| Post step 3 twice | Second reply stored, request stays `success`, no second Claude call |
| Raise twice for the same DOT | Same request back, one mail |

The last two are the ones to actually run — they are what stops the feature
mailing an agency repeatedly or billing twice for one reply.

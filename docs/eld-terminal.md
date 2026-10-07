# ELD / telematics connections (Terminal)

Step 4 of carrier onboarding. The carrier links their ELD provider — Samsara,
Motive, Geotab and the rest — through [Terminal](https://withterminal.com), and
the broker gets their fleet, hours of service and vehicle positions without
asking for them load by load.

The carrier signs in on Terminal's page, not ours. Their provider credentials
never reach this application, which is what the step promises them on screen.

## Configuration

Everything is read through `config('services.terminal.*')`, and nothing about
the environment is hardcoded. The two hosts follow from `TERMINAL_ENVIRONMENT`
rather than being spelled out again, because a production key pointed at the
sandbox host fails in ways that look like a broken integration rather than a
wrong setting.

```dotenv
TERMINAL_ENABLED=true
TERMINAL_ENVIRONMENT=sandbox          # or: production

TERMINAL_PUBLISHABLE_KEY=pk_sandbox_...
TERMINAL_SECRET_KEY=sk_sandbox_...

# From the webhook endpoint's page in the Terminal portal. Set per environment
# — a sandbox secret does not verify production deliveries.
TERMINAL_WEBHOOK_SECRET=whsec_...

# Days of history pulled when a carrier first connects. The biggest single
# lever on the bill; 0 means "from now on".
TERMINAL_BACKFILL_DAYS=0

# Overlap re-read on each locations pass, to catch pings that arrive late.
TERMINAL_LOOKBACK_HOURS=48
```

Promoting to production is three values: `TERMINAL_ENVIRONMENT=production`, the
two `_prod_` keys, and the production webhook secret. `TERMINAL_BASE_URL` and
`TERMINAL_LINK_URL` exist as overrides and should normally stay unset.

`TERMINAL_ENABLED=false` leaves the ELD step rendering and skippable but not
startable — the right behaviour while provider credentials are pending, rather
than blocking every carrier's onboarding on it.

The secret key never leaves the server. The publishable key is public by design
and only appears in the Link URL the carrier's browser follows.

## The shape of it

A connection belongs to the **carrier**. Consent belongs to the **broker**.

That split is the load-bearing decision here. A carrier hauling for three
brokers on this platform runs the Link flow three times against the same
Samsara account, and Terminal meters the data synced for a connection — so a
connection per broker would import, and bill for, the same fleet three times.

Terminal dedupes on the provider account plus the `external_id` we send, so
`EldConnectionService::externalIdFor()` returns `dot:{DOT number}` and nothing
else. Put the broker in that string and dedupe stops working. The second
broker's flow then lands on the connection that already exists, and a row in
`eld_connection_grants` records that this broker may see it.

Revoking one broker's grant leaves the others working. The connection is only
archived once nobody is left looking at it.

| Table | What it holds |
| --- | --- |
| `eld_connections` | One per carrier. Holds the encrypted connection token. |
| `eld_connection_grants` | Which broker company may see it, and when consent was given or withdrawn. |
| `eld_vehicles`, `eld_drivers` | The fleet. |
| `eld_hos_logs` | Duty status changes — what says whether a driver was legal to run a load. |
| `eld_locations` | Vehicle positions. |
| `eld_sync_checkpoints` | How far each resource has been read. |
| `eld_webhook_events` | Every event acted on, so a retry is a no-op. |

## Flow

1. `POST /carrier-connect/eld/connect` mints the Link URL: publishable key, the
   broker's consent template, a `state` nonce stored on the connect request, and
   `external_id`. A carrier whose connection has gone stale gets the re-auth URL
   for the connection they already have instead of a fresh flow.
2. The carrier signs in at Terminal and is redirected back with `result`,
   `token` and `state`.
3. `POST /carrier-connect/eld/verify` checks the state, exchanges the single-use
   public token for the connection token, dedupes, records the grant, and queues
   `SyncEldConnection`.
4. The wizard moves on. The fleet is still importing, and the tile says so
   rather than showing a tick beside an empty fleet.

The redirect is not trusted as the only signal — carriers close the tab. The
webhook at `POST /eld/terminal/webhook` is the backstop.

## Webhooks

Terminal delivers through Svix. The signature covers `id.timestamp.body`,
HMAC-SHA256 against the raw bytes of `whsec_...`. An endpoint with no secret
configured rejects everything rather than trusting an unverified payload.

| Event | What happens |
| --- | --- |
| `connection.created` | Usually already stored by the exchange. Logged loudly if not — it means a carrier believes they are connected and we disagree. |
| `connection.disconnected` | Connection marked disconnected; the wizard starts offering re-auth. |
| `sync.completed`, `vehicle.added`, `driver.added` | Queues an incremental sync. |
| `sync.failed` | Recorded on the connection. |
| `safety_event.added` | Stored in `eld_webhook_events`, not yet acted on — see below. |
| `delivery.*` | Acknowledged and ignored. We use the API, not Data Delivery. |

Handling is idempotent on the event id, because Terminal retries anything not
answered 2xx and a duplicate sync is not merely wasted work — it is billable.

## Syncing, and the bill

Terminal charges for data synced, not for vehicles and drivers held. Three
things follow from that, and all three are deliberate:

- **Incremental, never a full re-read.** Entities and HOS are read by ingestion
  time (`modifiedAfter`), which is monotonic and needs no lookback. Locations
  can only be read by record time (`startAt`), so that one re-reads a 48-hour
  overlap and the unique key collapses the duplicates.
- **A failed pass does not advance its checkpoint.** Otherwise the window it
  never read is skipped for good.
- **Only carriers under load are polled.** `eld:sync-active` runs hourly and
  scopes itself to carriers with an `active` shipment. `--all` exists for a
  one-off catch-up and is not what the scheduler runs.

The first import is dispatched by the exchange, not by the scheduler. Jobs run
on the `eld` queue, which the scheduler's worker drains after `default` and
`vin` — nothing is waiting on a fleet import.

## Wind-down

Archive stops the sync and the meter and keeps the history; delete erases it.
Archive is the default, because a broker settling a dispute over a load already
hauled still needs the positions and duty status from the day it ran. Delete is
for an explicit erasure request only — Terminal allows 24 hours to change your
mind, after which neither side can bring it back.

## Tests

```bash
php artisan test --filter=CarrierEldConnectionTest   # consent, dedupe, wind-down, webhooks
php artisan test --filter=EldFleetSyncTest           # paging, mapping, checkpoints, re-reads
```

Both run on sqlite through `Tests\PortableMigrations`, so no MySQL is needed.
Terminal itself is faked — nothing here reaches the network or the meter.

## Still to do

Things that are not code, or not this pass:

- **The webhook secret.** `TERMINAL_WEBHOOK_SECRET` is currently empty, and the
  endpoint refuses every delivery without it. Create the endpoint in the sandbox
  portal (`POST {APP_URL}/api/v1/eld/terminal/webhook`), then paste its signing
  secret in. Nothing else in the integration is blocked on configuration.
- **Provider app credentials.** Each provider that needs its own app registered
  with Terminal has to be applied for, and review is measured in weeks. Until it
  clears, carriers on that provider reach a Link page they cannot complete. This
  is the longest-lead item in the whole integration.
- **Consent templates.** `companies.eld_consent_template` is read and sent; the
  templates themselves are created in the Terminal portal, one per broker, so
  the Link page names the same broker the wizard does.
- **Cost projection.** Model three months before the first production
  connection: carriers invited per broker, expected connect rate, average fleet
  size, and `TERMINAL_BACKFILL_DAYS`.
- **HOS field names.** Terminal publishes provider support for HOS logs but not
  the object's shape. `EldSyncService::syncHosLogs()` accepts both plausible
  spellings and keeps the untouched row in `payload`; confirm against a sandbox
  response and drop the fallbacks.
- **Safety events.** `safety_event.added` is stored but not acted on. It is the
  real signal behind the Risk Alerts page, which currently ships sample rows.
- **Shipment tracking.** `shipments.tracking_method = 'eld'` is stored and still
  unread. The positions it needs are now in `eld_locations`.

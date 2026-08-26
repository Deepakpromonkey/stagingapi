# VIN decoding

Where the fleet table's **Year** and **Model** columns come from, and the
**Avg Power Age** / **Avg Trailer Age** cards on the carrier profile.

---

## 1. The source

NHTSA's vPIC decoder: <https://vpic.nhtsa.dot.gov/api>. Public, no key, no
account, CORS open. The FMCSA feed gives us a VIN and a make; it never gives a
model or a model year, which is why those columns were empty.

## 2. Why we do not decode VINs

`inspections` holds tens of millions of VIN values. Decoding them one by one —
whether on the request path or in a batch job — would never finish, and would
be almost entirely wasted work.

Year, make and model live in **positions 1-8 and 10** of a VIN. Position 9 is a
check digit; positions 11-17 are the assembly plant and the serial number. Those
last seven characters identify the individual unit and say nothing about what it
is. vPIC accepts a partial VIN and returns the identical answer:

```
1FUJGLDR*C        -> 2012 FREIGHTLINER Cascadia (Truck-Tractor)
1FUJGLDR9CLBP8834 -> 2012 FREIGHTLINER Cascadia (Truck-Tractor)
1FUJGLDR2CLBS9911 -> 2012 FREIGHTLINER Cascadia (Truck-Tractor)   different truck
```

So a carrier's 200-truck fleet of one spec and model year is **one** decode, and
every Cascadia of that year in the country shares it. We cache on the 9-character
**pattern** — positions 1-8 plus position 10 — not on the VIN.

`php artisan vin:backfill --count` prints the ratio for the live data. As of
the first run against production:

```
VIN rows (power unit column)   5,783,981
Distinct VINs                  2,512,732
Distinct patterns                 79,239
Rows per pattern                73.0 : 1
```

79k decodes instead of 2.5M — and at 50 patterns per vPIC batch that is 1,585
requests, not 50,000.

## 3. The pieces

| | |
|---|---|
| `App\Support\Vin` | Pattern extraction, validation, partial-VIN formatting. No I/O. |
| `vin_patterns` | The cache. Primary key is the pattern. Small enough to stay in memory. |
| `VinDecoderService::lookup()` | **Request path.** One indexed query. Never calls NHTSA. |
| `VinDecoderService::decode()` | **Queue path only.** The one place that calls NHTSA. |
| `DecodeVinPatterns` (job) | One batch of ≤50 patterns per vPIC POST. |
| `FleetStatsService` | Averages ages per carrier into `carrier_fleet_stats`. |
| `vin:requeue` | Reconciler. Re-dispatches pattern rows that lost their job. |
| `RefreshFleetStats` (job) | Recomputes one carrier, off the request path. |

Nothing on the request path may call `decode()`. A pattern that is not cached
yet is simply a null the frontend renders as a dash.

## 4. Running it

```bash
# 1. See the size of the job first.
php artisan vin:backfill --count

# 2. A worker has to be draining the vin queue for anything to decode.
php artisan queue:work database --queue=vin

# 3. The one-time sweep. Resumable — an interrupted run costs only the scan.
#    NOTE: --count queues nothing. This is the command that fills the queue;
#    a worker started before it will simply sit idle until this runs.
php artisan vin:backfill

# 4. Fleet ages for the carriers people actually look at.
php artisan carrier:refresh-fleet-stats --active
```

After that the scheduler in `routes/console.php` keeps both current, and a
profile view queues a refresh for any carrier whose figures are missing or past
`vin.fleet_stats_ttl`.

### The queue connection is pinned, on purpose

`QUEUE_CONNECTION` is `sync` in this application. On `sync` a dispatched job
runs **inline and immediately**, and its delay is ignored — which would put
every vPIC call and every fleet-age aggregation back on the request path, and
would mean `queue:work` sits idle forever because nothing ever reaches the jobs
table.

So `DecodeVinPatterns` and `RefreshFleetStats` set their connection explicitly
from `vin.connection` (default `database`) rather than inheriting it. Change
that with `VIN_QUEUE_CONNECTION`, not with `QUEUE_CONNECTION` — flipping the
global would change behaviour for every other job in the application.

The worker therefore names the connection too:

```bash
php artisan queue:work database --queue=vin
```

`vin:status` refuses to report anything if the connection resolves to a sync
driver, because every number it could show would be a lie.

### An idle-looking worker is usually not stuck

Batches are dispatched with a delay so they reach NHTSA at the configured pace.
A job whose delay has not elapsed is invisible to `queue:work` — so the worker
prints its banner and sits there, which looks exactly like a hang. Check before
assuming:

```bash
php artisan vin:status          # or --watch
```

`runnable right now` is the number that matters. If it is 0 and `next batch
runs in` shows a countdown, the worker is paced, not stuck.

The other reason for a silent worker is the obvious one: `vin:backfill --count`
measures and queues nothing. Only `vin:backfill` fills the queue.

### Stranded pending patterns

A pattern row is written *before* its decode job is dispatched, so the two can
come apart — a killed worker, a `queue:clear`, a job that exhausted its
attempts. The row is then stranded: `vin:backfill` and `queueUnknown()` both
skip any pattern that already has a row, deliberately, so that a decided
`failed` is never retried forever — and neither can distinguish a stranded row
from one legitimately waiting its paced turn.

`vin:requeue` is the reconciler, scheduled hourly. Two guards keep it from
duplicating live work, and the second is the one that matters:

* rows must have been pending longer than `--older-than` (30 minutes default);
* **the queue must be empty**. A backfill paces its batches over hours — the
  last of 1,400 batches at 15/min is scheduled 95 minutes out — so a pattern
  can sit pending far longer than any age guard while its job is queued and
  perfectly healthy. Age cannot tell that apart from a stranded row. A pattern
  is only genuinely orphaned once nothing is in flight, so that is when this
  runs. `--force` overrides it.

```bash
php artisan vin:requeue --dry-run
```

If `vin:status` shows `pending` sitting still while `jobs waiting` is 0, this is
what to run.

## 5. Pacing

At the default 6 batches/min, the 79,239-pattern backfill takes about 4.5
hours. That is a one-time cost, and it is fine to raise the pace for it:

```bash
VPIC_BATCHES_PER_MINUTE=15 php artisan queue:work --queue=vin   # ~1h45m
```

Put it back to the default afterwards — the steady-state load is a few hundred
new patterns a day, which needs no pace at all.

vPIC publishes no rate limit, which is not the same as not having one. Batches
are spaced to `vin.batches_per_minute` (default 6/min = 300 patterns/min) and
`DecodeVinPatterns` carries a `WithoutOverlapping` middleware so two workers
cannot decode in parallel and defeat the pacing. Raise it if a backfill needs
to move faster, but keep it civil — this is a free government service.

A pattern that fails `vin.max_attempts` times is marked `failed` and never
retried. That is deliberate: without it, one junk VIN would be re-queued by
every carrier profile that contains it, forever.

## 6. What we deliberately did not do

NHTSA publishes the whole vPIC database as a downloadable SQL Server backup, so
we could self-host and never make a network call. It is a `.bak` with a large
stored-procedure decode layer behind it; porting that to MySQL is a project on
its own, and the pattern cache already shrinks the problem by orders of
magnitude. Not worth it.

## 7. Coverage, measured

From the first full production run — 4,576,764 VIN values across both unit
columns, 243,979 distinct patterns:

| | |
|---|---|
| Power units (`vin`) | ~90% decode |
| Trailers (`vin2`) | far lower |
| Overall | ~71% |

The gap is NHTSA's, not ours. vPIC has no model-year data for most small
trailer manufacturers — they do not use the standard position-10 model-year
encoding, so a VIN like `1W9TS392*5` comes back completely empty, and
`57133000*0` returns `LARK UNITED MANUFACTURING`, VehicleType `TRAILER`, and no
year at all.

Two consequences worth designing around, both handled:

* **Partial answers are kept.** A make and body class with no model year still
  fills the Make and Model columns and classifies the unit as towed equipment.
  `model_year` stays null so the row contributes nothing to the age averages.
* **A clean "no data" retires immediately.** vPIC answering with nothing is
  definitive, not transient — retrying it twice more only rediscovers the same
  silence. Only a request that could not be completed is retried.

Expect `AVG TRAILER AGE` to rest on fewer VINs than `AVG POWER AGE`. That is
why `carrier_fleet_stats` carries `vins_decoded` against `vins_total` and the
card prints the count it is based on.

## 8. Caveats

* Power unit vs. trailer comes from vPIC's `VehicleType`/`BodyClass`, not from
  the feed's `unit_type_desc` — the feed writes `TRUCK TRACTOR`, `TRACTOR` and
  `STRAIGHT TRUCK` for the same thing.
* Averages are over **distinct VINs**, not inspection rows. A truck stopped nine
  times would otherwise count nine times.
* An age below 0 or above `vin.max_plausible_age` is discarded as a bad decode.
  vPIC does report next year's model year on new equipment.
* `vins_total` vs. `vins_decoded` on `carrier_fleet_stats` is the honest measure
  of coverage for a carrier. A number built from three VINs out of two hundred
  should not be read as fleetwide.

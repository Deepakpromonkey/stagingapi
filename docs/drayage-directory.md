# Drayage directory

Drayage carriers imported from the **LoadMatch / Drayage.com directory**
(the export of the internal Drayage Carrier Finder), searchable through
`/api/v1/drayage/...` and matched to DollarTraq's own carrier records by
USDOT.

The dataset lives in **JSON files on the API box, never in MySQL**. Each import
builds a new dataset version beside the old ones; an atomic pointer swap
makes it live, and a rollback is the same swap pointed at an older version.

```
CSV / JSON upload ─► imports/originals/{id}.csv
                  ─► ImportDrayageDataset job (database queue, `drayage`)
                  ─► parse ─► normalize ─► merge duplicates ─► validation gate
                  ─► datasets/.building-{id}/  (carrier files, index, facets, manifest)
                  ─► rename to datasets/{id}/ ─► current.json ─► live
```

---

## 1. Setup

1. Deploy the code and sync the permissions (adds three, see §4):

   ```
   php artisan db:seed --class=RolePermissionSeeder
   ```

2. Make sure the store is writable by both PHP-FPM and the CLI user that
   runs the queue worker (`storage/app/drayage` by default):

   ```
   sudo -u www-data mkdir -p storage/app/drayage
   ```

3. Give the staff who run imports the admin permission:

   ```
   php artisan drayage:grant someone@promonkey.tech
   ```

4. Do the first import from the shell, which needs no upload limits:

   ```
   php artisan drayage:import /path/to/drayage_carriers_2026-10-01_4664.csv
   ```

5. Optional: issue Fleetra its read-only token (§6).

Uploading through `POST /drayage/imports` also needs nginx
`client_max_body_size` and php.ini `upload_max_filesize` / `post_max_size`
raised to at least the file size (the real export is ~3 MB; the defaults are
1 MB / 2 MB / 8 MB).

## 2. Configuration

`config/drayage.php`, every key overridable from `.env`:

| Key | Env | Default | |
|---|---|---|---|
| `root` | `DRAYAGE_ROOT` | `storage/app/drayage` | The store. Private; nothing serves it. |
| `import.max_upload_kb` | `DRAYAGE_MAX_UPLOAD_KB` | 51200 | Upload size limit. |
| `import.max_rejected_percent` | `DRAYAGE_MAX_REJECTED_PERCENT` | 5 | Above this, the import fails and nothing changes. |
| `import.timeout` | `DRAYAGE_IMPORT_TIMEOUT` | 300 | Job timeout, seconds. Also bounds the import lock. |
| `import.memory_limit` | `DRAYAGE_IMPORT_MEMORY_LIMIT` | 512M | Raised for the import only. |
| `queue.connection` / `queue.name` | `DRAYAGE_QUEUE_CONNECTION` / `DRAYAGE_QUEUE` | `database` / `drayage` | The scheduler's worker drains `drayage` (routes/console.php). |
| `retention` | `DRAYAGE_RETENTION` | 5 | Dataset versions kept, the live one included. Staging uses 3 (disk). |
| `cache.store` | `DRAYAGE_CACHE_STORE` | app default | Where the decoded index is cached. `none` reads the file every request. |
| `cache.ttl` | `DRAYAGE_CACHE_TTL` | 86400 | Seconds. Keys include the dataset id, so a switch needs no flush. |
| `backup.enabled` | `DRAYAGE_S3_BACKUP` | false | `.tar.gz` of each activated dataset to S3. |
| `backup.prefix` | `DRAYAGE_S3_PREFIX` | `staging/drayage/` | Staging and production share one bucket - keep the prefixes apart. |
| `rate_limits.read` / `.export` / `.admin` | `DRAYAGE_*_RATE_LIMIT` | 120 / 5 / 60 | Per user per minute, each its own counter. |
| `export_max_rows` | `DRAYAGE_EXPORT_MAX_ROWS` | 10000 | |

Audit and import lines also go to the `drayage` log channel
(`storage/logs/drayage-*.log`, kept `DRAYAGE_LOG_DAYS`, default 400).

## 3. Storage layout

```
storage/app/drayage/
  current.json                               live pointer: dataset_id, activated_at/by, previous_dataset_id
  audit.jsonl                                one line per import, activation, deletion, export
  imports/{import_id}.json                   status (queued → processing → completed | failed) + report
  imports/originals/{import_id}.csv          the file as uploaded
  datasets/{dataset_id}/manifest.json        source file + sha256, counts, field coverage, warnings, headers
  datasets/{dataset_id}/carriers/{key}.json  one document per carrier (detail reads)
  datasets/{dataset_id}/index/records.json   filterable fields + search text (the only file a search loads)
  datasets/{dataset_id}/index/lookup_usdot.json | lookup_mc.json | lookup_scac.json
  datasets/{dataset_id}/index/facets.json    whole-dataset option counts and numeric bounds
```

Ids sort by creation time: `imp-20261002T182417123Z-27f908`,
`ds-20261002T182417123Z-ee4a4e`. Carrier keys are `lm-{LoadMatch ID}`, or
`ls-` plus a hash of the normalized name, HQ city and HQ state for
directory listings, which have no ID. The same carrier keeps its key on
every re-import.

**Footprint.** The 4,664-carrier export makes a ~24 MB dataset (mostly
filesystem blocks for 4,664 small files; `records.json` is 5.3 MB). At
retention 5 that is ~120 MB, plus ~3 MB per kept original.

### The carrier document

Sections, in order: `meta`, `identity`, `location`, `coverage`, `authority`,
`insurance`, `compliance`, `drayage`, `special_cargo`, `fleet`, `equipment`,
`contact`, `profile_dates`, `links`, `extra` (columns the dictionary does not
know, verbatim). `meta.summary` is a one-line description for LLM use.

Rules that hold everywhere:

- **Booleans are tri-state.** `true` / `false` / `null`, and `null` means the
  profile does not say. A blank cell is never `false`.
- **Identifiers are strings.** USDOT, MC, ZIP, LoadMatch ID, phones and bond
  numbers keep their leading zeros. `MC-007788` is stored as `007788`.
- **Derived fields are recomputed on import:** `years_in_business`,
  `owner_op_pct` (only when both driver counts exist), `completeness` (share
  of 12 key fields present), `search_text`.
- An unreadable value (`N/A` in a number column, `2020-13-45`, a 7-letter
  SCAC, an invalid state code) becomes `null` with a warning in the import
  report. Only a row with no company name, or with the wrong number of
  columns, is rejected.
- Rows sharing a carrier key merge: the fuller row wins, and metros, metro
  codes, emails, phones and terminals are unioned.

The field dictionary (121 columns: key, label, type, group, section) is in
`app/Services/Drayage/DrayageFields.php` and served by `GET /drayage/fields`.

## 4. Permissions

| Who | Permission | Can |
|---|---|---|
| Every broker seat | `view-carrier-directory` (existing) | All read endpoints |
| Compliance Manager, Owner/Admin | `export-drayage-directory` | `GET /drayage/export` |
| DollarTraq staff, by `drayage:grant` | `manage-drayage-directory` | Imports, datasets, activate, delete |
| Service accounts, by `drayage:service-token` | `read-drayage-directory` | Read endpoints only |

`manage-drayage-directory` and `read-drayage-directory` are in no role, so no
broker company can grant them to itself.

## 5. API

All under `/api/v1/drayage`, Sanctum auth, the usual envelope
(`{status, message, data}`; validation errors are Laravel's 422
`{message, errors}`). Before the first import, reads answer 404
*"No drayage dataset is live yet."* (`/fields` works regardless).

### Reads

| | |
|---|---|
| `GET /carriers` | Search, filters, facets, sort, pagination. |
| `GET /carriers/{carrier_key}` | Full document. `include=fmcsa,trust_score,onboarding`. |
| `GET /carriers/lookup?usdot=` \| `mc=` \| `scac=` | Every carrier with that identifier (MC and SCAC can name several terminals). |
| `GET /facets` | Whole-dataset option counts and numeric bounds. |
| `GET /fields` | The field dictionary and the filter grammar. |
| `GET /stats` | Totals, full vs listing, metro counts, field coverage, live dataset. |

### `GET /carriers` parameters

| Parameter | |
|---|---|
| `q` | Free text; tokens AND-ed. An exact USDOT, MC (`MC-123` too), SCAC or LoadMatch ID matches on its own and ranks first, then name prefix, then name tokens, then anything else. Relevance is the order when `q` is given without `sort`. |
| `{bool}=yes\|no\|unknown` | Any of the 53 boolean fields, e.g. `hazmat=yes`, `oog=unknown`. |
| `has[]=` | `scac`, `mc`, `usdot`, `firms_code`, `canadian_authority` (NSC/CVOR/NIR), `phone`, `emails`, `website`, `pricing_email`, `dispatch_email`, `contact_people`. AND-ed. |
| `{num}_min`, `{num}_max` | Every numeric field, plus `authority_year` and `first_added_year`. Inclusive; records with no value are excluded. |
| `{list}[]=`, `{list}_mode=any\|all` | `metros`, `city_codes`, `hq_state`, `hq_country`, `states_served`, `provinces_served`, `terminals`, `languages`. Case-insensitive. `all` is for list fields only. |
| `record_type` | `full`, `listing` or `all` (default), or `record_type[]=`. |
| `updated_within_days` | Profile updated in the last N days. |
| `sort` | Comma list of sortable keys, `-` for descending. Default `-completeness,company_name`. Nulls last either way. |
| `page`, `per_page` | Default 25, max 500. |
| `fields` | Comma list; default is the card set (`card_fields` in `/fields`). Fields outside the index are read from the carrier files for that page only. |
| `facets` | `true` (default) / `false`. |
| `include` | `trust_score`, `onboarding` - batched for the page. |

Anything else is a 422; a misspelt filter never silently does nothing.

Response `data`: `carriers`, `pagination`, `facets` (`booleans` → yes/no/unknown,
`presence` → present/absent, `values` → option lists), `summary` (total,
median drivers, median cargo insurance, % yes of stated for hazmat, reefer,
TWIC, private chassis), `dataset`, `source`.

Facets are **exclude-self**: each one is counted over every active filter
except its own, so its options show what changing that one filter would
return. For any boolean, yes + no + unknown equals the total the other
filters give.

### Includes - what DollarTraq knows about the carrier

- `trust_score` - `{score, grade, status}` from the same cache Find New
  Partner and the profile use (`carrier_dt_score:{dot}`). A carrier with no
  score yet is queued for the same background scoring job and returned as
  `status: "pending"`; poll the existing `GET /carrier/scores?dots[]=...`.
  Never computed on the request.
- `onboarding` - `in_dollartraq` (the USDOT is in the FMCSA census), the
  caller's company's latest carrier-connect request for it (status, stage),
  and `actions.next_action`: `view_onboarding`, `invite` or `unavailable`
  (with a reason).
- `fmcsa` (detail only) - operating status, authority (common / contract /
  broker, revocation pending), active out-of-service orders, insurance on file
  and live filings, 24-month inspections and OOS rates, last inspection date.

### Inviting a drayage carrier to onboard

Through the existing endpoint, unchanged - no second invite path. With
`include=onboarding`, `actions.invite` carries the request ready to send:

```
POST /api/v1/carrier-connect                      (send-invitation-approved-carriers)
{"row_id": "<usdot>", "email_option": "fmcsa"}
```

`fmcsa` mails the address on the FMCSA record. To use the directory's
dispatch email instead, send `actions.invite.alternate_body`
(`email_option: "alternate"`); as with any alternate address, the carrier
approves it first. A carrier with no USDOT, or one not in the census, cannot
be invited this way.

### Export

`GET /drayage/export` with the same filters and `sort` (no paging). CSV, UTF-8
with BOM, the export's own column labels (so an export re-imports cleanly),
booleans as Yes / No / blank, lists joined with `; `. Cells starting with
`=`, `+`, `-`, `@`, tab or CR are prefixed with `'`. `fields=` picks columns.
Only `format=csv` for now; XLSX needs a spreadsheet library and was deferred.

### Admin

| | |
|---|---|
| `POST /imports` | Multipart `file` (`.csv`, `.json`, `.jsonl`; optional `format`). 202 with `import_id`. |
| `GET /imports`, `GET /imports/{id}` | Status and report: rows read / imported / merged / rejected, rejected rows with reasons (first 500), warnings by field, unknown and missing columns, field coverage, duration, who. |
| `GET /datasets` | Versions with manifests, size, which is live. |
| `POST /datasets/{id}/activate` | Rollback or roll forward. |
| `DELETE /datasets/{id}` | Not the live one (409). |

## 6. Fleetra

```
php artisan drayage:service-token            # prints the token once
php artisan drayage:service-token --revoke   # deletes its tokens
```

The token belongs to a service account with no company and no role, whose
only permission is `read-drayage-directory`; the token's only ability is
`drayage:read`, and `EnsureBrokerUser` refuses it on every broker route
except the drayage reads. Send it as `Authorization: Bearer <token>`.

For an LLM, `fields=summary,...` gives a one-line description per carrier;
`/fields` documents every key. Responses are stable: new fields may be
added, existing ones will not change meaning.

## 7. Runbook

**Import a new export**

```
php artisan drayage:status                           # what is live now
php artisan drayage:import /home/ubuntu/new.csv      # or POST /drayage/imports
php artisan drayage:status                           # new dataset live, old one kept
```

A failed import (over 5 % rejected, no rows, missing `Company`, unreadable
file) changes nothing: the previous dataset stays live and
`imports/{id}.json` says why. Only one import runs at a time; a queued one
waits for the lock and retries every 30 s for up to ten minutes.

**Roll back**

```
php artisan drayage:status                           # pick the dataset id
php artisan drayage:activate ds-20261002T182417123Z-ee4a4e
```

or `POST /drayage/datasets/{id}/activate`. Takes effect on the next request;
nothing to restart or flush.

**A queued upload never runs** - the worker is the scheduler's
`queue:work database --queue=default,drayage,vin,eld`, so check cron is
running `schedule:run`. `php artisan drayage:status` shows the import still
`queued`.

**Disk.** Retention prunes after every successful import. `drayage:status`
shows each dataset's size.

## 8. Limits and the scaling path

A search loads the whole index (5.3 MB of JSON, ~35 MB decoded) and filters it
in PHP. Measured on the 4,664-carrier export: decode 30 ms (14 ms from the
cache), the heaviest query - no filters, every facet over every carrier -
36 ms, ten filters 3 ms. An import takes ~1.1 s and ~100 MB of memory.

That holds to roughly 20-50k carriers. Past that, move the index to SQLite
(one file per dataset, same atomic swap) or a search engine; the storage
class and the query engine are the only code that would change.

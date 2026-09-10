# Carrier database (EC2) — data notes and tuning options

Findings from going through `external_db` (`dollar_traq` on the EC2 host) while
fixing carrier search and the carrier profile after the Motus load.

Nothing here has been applied. The application treats this database as
read-only and runs no migrations against it, so each item below is a decision
for whoever owns the load.

---

## 1. What the Motus load changed

The code was written against the previous feed. These are the encodings that
moved. All of them are now handled in `App\Support\Fmcsa`, so application code
should go through that rather than comparing against a literal.

| Column(s) | Was | Is now |
|---|---|---|
| `carrier_authorities.common_stat` / `contract_stat` / `broker_stat` | `'ACTIVE'` | `'A'` / `'I'` / `'N'` |
| `carriers.hm_flag`, `pc_flag`, `authorized_for_hire`, and the other 15 operation flags | `'Y'` / `'N'` | `'true'` / `'false'` |
| `carrier_authority_history` authority type | `mod_col_1` | `op_auth_type`, holding **both** the long description (`PROPERTY BROKER`) and a short form (`BROKER`) |
| `sms_measures` | `*_pct`, `*_basic_alert`, `*_rd_alert` | dropped — only `*_measure`, `*_ac`, `*_insp_w_viol` survive |

`carrier_authorities.cargo_req`, `bond_req`, `*_app_pend` and `*_rev_pend` are
still `'Y'` / `'N'`, and `carrier_oos_orders.status` is still spelled out in
full (`'ACTIVE'` / `'INACTIVE'`) — the change was not uniform.

Two columns look boolean but are not: `bipd_file` is a coverage amount in
thousands, zero-padded (`'00000'` means nothing on file), while `cargo_file`
and `bond_file` are `'Y'` / `'N'`.

Dates come in three shapes depending on the table: `24-APR-24`
(`carriers`, `inspections`, `crashes`, `carrier_authority_history`),
`09/23/2004` (`insurance_filings`) and plain ISO (`carrier_oos_orders`).
Sorting or `MAX()`-ing the first shape as text gives the wrong answer.

---

## 2. Gaps in the load

| Table | Rows | Note |
|---|---|---|
| `sms_measuresab` | 0 | Empty. Carries the `*_pct` / `*_basic_alert` / `*_rd_alert` columns that `sms_measures` lost — looks like the staging table for the old shape. Either drop it or repopulate it. |
| `sms_measures.*_ac` | all 0 | The acute/critical indicator is present but never populated. Scoring reads it, so it starts counting the moment the loader fills it. |
| `insurance_filings_history` | 0 | Profile scoring deducts "No Insurance History" from every carrier because of this. |
| `insurance_filings_pending` | 0 | |
| `inspection_citations` | 0 | Eager-loaded by the profile on every request. |
| `inspection_violations` | 0 | |
| `broker_insurance` | 0 | |

`carrier_details` covers ~99.9% of carriers; the rest have no row at all, so
`duns`, `fleet_size`, `active_authority` and `risk_level` come back null for
them. That is now handled rather than throwing.

---

## 3. Duplicate rows in `carrier_details`

17,880,701 rows against 2,075,018 carriers — roughly three identical rows per
DOT number. Verified identical on content, so they are repeated loads rather
than revisions.

Handled in the application for now: the `carrierDetail` relation and the search
subselects both pin to `MAX(id)`. De-duplicating at the source would shrink the
table ~5x and take the sort out of every lookup.

**This deletes data — take a snapshot first, run it off-peak, and confirm the
count before and after.**

```sql
-- Check first. Expect ~2.1M distinct against ~17.9M rows.
SELECT COUNT(*) AS rows_now, COUNT(DISTINCT dot_number) AS distinct_dots
FROM carrier_details;

-- Keep the newest row per DOT number.
DELETE cd FROM carrier_details cd
JOIN (
    SELECT dot_number, MAX(id) AS keep_id
    FROM carrier_details
    GROUP BY dot_number
) k ON k.dot_number = cd.dot_number AND cd.id <> k.keep_id;

-- Then stop it recurring.
ALTER TABLE carrier_details ADD UNIQUE KEY uk_cd_dot (dot_number);
```

`carrier_authorities` has the same problem on a much smaller scale (a handful of
DOT numbers with 2-4 rows), plus 26,310 rows with an empty `dot_number`.

---

## 4. Name search performance

`legal_name LIKE '%term%'` can only be answered by scanning the whole index:

| Query | Time |
|---|---|
| `COUNT(*)` over `carriers` | 2.3s |
| `COUNT(*)` with `legal_name LIKE '%SWIFT%'` | 4.6s |
| `COUNT(*)` with `legal_name LIKE '%SWIFT%' OR dba_name LIKE '%SWIFT%'` | 15.3s |
| the same page query with `LIMIT 10` | 0.3s |

The application now caches that count for 10 minutes and skips it entirely for
DOT / MC / phone / email lookups, which is what made search usable without
touching this database.

A FULLTEXT index would make the count itself fast:

```sql
ALTER TABLE carriers ADD FULLTEXT INDEX ft_carriers_name (legal_name, dba_name);
```

**It is not wired up, deliberately.** FULLTEXT matches whole words and word
prefixes, so it would not find `SWIFT` inside `AIRSWIFT` the way `LIKE '%…%'`
does. Switching to it is a product decision about what a name search should
mean, not a drop-in swap. If that trade is acceptable, say so and the query can
move to `MATCH … AGAINST` behind the same endpoint.

---

## 5. Connection cost

The EC2 host is ~250ms away per round trip, and **opening** the connection costs
a further ~800ms — paid on every request that touches a carrier.

`EXTERNAL_DB_PERSISTENT=true` (added to `.env`, off by default) reuses the
socket and removes the handshake. Persistent handles are held per PHP-FPM
worker, so raise `max_connections` on the instance to at least the worker count
before enabling it.

The carrier profile still makes ~20 round trips, four of which fetch the empty
tables in section 2. Fixing the load would take those back automatically.

---

## 6. The application now reads the `carrier` database

`EXTERNAL_DB_DATABASE=carrier`. `dollar_traq` is no longer read by anything
except the one-off id remap migration below.

The `carrier` database is a raw landing zone: `_load_manifest` shows it was
produced by `~/Downloads/promonkey/dot-extractor`, a generic CSV bulk loader
that "creates the database and tables automatically from the CSV itself. No
schema work up front." So the tables carry FMCSA source-file names and shapes
plus `_row_id` / `_source_file` / `_loaded_at`, and the loader adds no indexes.
`motus_sync_run` and `motus_change_log` track incremental Socrata feed syncs.

Three things were needed to put the application on it.

### 6.1 Views (`database/sql/carrier_views.sql`)

Twenty views translate the source vocabulary into the vocabulary the
application speaks, so no model or controller had to be rewritten around
source-file column names. They are plain projections, so MySQL uses the MERGE
algorithm and the underlying indexes still apply.

| App view | Source table |
|---|---|
| `carriers`, `carrier_details` | `company_census_file` |
| `carrier_census` | `sms_input_motor_carrier_census_information` |
| `carrier_authorities` | `carrier_all_with_history` |
| `carrier_authority_history` | `authhist_all_with_history` |
| `carrier_authority_orders` | `revocation_all_with_history` |
| `carrier_contacts` | `boc3_all_with_history` |
| `carrier_oos_orders` | `out_of_service_orders` |
| `sms_measures` | `sms_ab_passproperty` |
| `inspections` | `sms_input_inspection` |
| `violation_details` | `sms_input_violation` |
| `inspection_units` | `inspections_per_unit` |
| `inspection_citations` | `inspections_and_citations` |
| `inspection_violations` | `vehicle_inspections_and_violations` |
| `crashes` | `sms_input_crash` |
| `crash_details` | `crash_file` |
| `insurance_filings` | `inshist_all_with_history` |
| `insurance_filings_history` | `actpendinsur_all_with_history` |
| `insurance_filings_pending` | `rejected_all_with_history` |
| `broker_insurance` | `insur_all_with_history` |

Two rules hold the performance together, and both are easy to undo by accident:

* **Any column the app filters or sorts on must be a bare base column.** An
  expression or a `COALESCE` across two tables cannot use an index. This is why
  the MCS-150 operation flags are a separate `carrier_census` view rather than
  a `LEFT JOIN` into `carriers` — joining them turned the search's paging query
  from a 0.4s index scan into a **38s full scan of 4M rows**.
* **`id` and `row_id` are synthesised**, because the landing tables have
  neither. On `carriers` both are the DOT number; elsewhere they are `_row_id`.
  `row_id` is a `CAST`, so never filter on it — the profile now looks up
  `dot_number` directly.

### 6.2 Indexes (`database/sql/carrier_indexes.sql`)

Re-run this after the loader recreates a table. About 20 minutes end to end.
Two findings worth keeping:

* `idx_legal` must be `(legal_name, dot_number)` at **full length**. A prefix
  index cannot satisfy `ORDER BY`, and InnoDB appends the primary key —
  `_row_id`, not `dot_number` — so without `dot_number` in the key the page
  query is a full scan plus filesort.
* `crash_file(report_number)` is not optional: `crashes->detail()` joins on it
  and the profile spent **51 of its 68 seconds** there.

The L&I tables zero-pad `dot_number` to eight characters (`'00100011'`) while
the census/SMS tables store a plain int, so those tables get an indexed
generated column `dot_int` that the views expose as `dot_number`.

### 6.3 Local id remap

`carrier_shortlists.carrier_id` and `search_histories.carrier_id` held
`dollar_traq.carriers.id`, a surrogate key that no longer exists. The migration
`2026_08_18_120000_remap_carrier_ids_to_dot_numbers` rebuilds them from the old
table, which is still on the same server. **It has not been run** — the local
database was not reachable from where this work was done. Run it before
trusting shortlists or search history.

### What improved

* 4,481,701 carriers, up from 2,075,018.
* One row per DOT number — the old `carrier_details` had roughly three.
* `insurance_filings_history`, `insurance_filings_pending`, `inspection_units`,
  `inspection_citations`, `inspection_violations` and `broker_insurance` all
  have data now; every one of them was empty before.
* Authority ages resolve (they were always null), real `DATE` columns, and
  `tinyint` booleans instead of `'true'` / `'false'` strings.

### Known gaps

* **Phone numbers are unformatted** (`8006540055`, previously
  `(800) 654-0055`). The phone search strips non-digits from the term so both
  spellings work, but any consumer comparing the stored value will see the
  change.
* **MCS-150 operation flags cover ~761k of 4.48M carriers.**
  `sms_input_motor_carrier_census_information` is a different population from
  `sms_ab_passproperty` — DOT 58192 has SMS measures but no census row. So
  `company_snapshot` is mostly null, and `not_authorized_for_hire` now returns
  `null` (unknown) rather than asserting the negative.
* **Nested `inspections[].units` / `.citations` / `.violations` are empty.**
  Those tables key on the MCMIS `inspection_id` (~79-85M) used by
  `vehicle_inspection_file`, while `inspections` is built on
  `sms_input_inspection`, whose `unique_id` is a different id space (~737M).
  The two only meet through `report_number`, and the sampled rows do not
  overlap — the SMS extract is a 24-month window, the MCMIS file is everything.
  Top-level `violation_details` is unaffected and does resolve.
* **371 DOT numbers** are in the SMS census but missing from
  `company_census_file`, so they have no carrier record at all. DOT 100011 is
  one of them.
* `sms_ab_passproperty` still has no `*_pct`, `*_basic_alert` or `*_rd_alert`
  columns, and `*_ac` is still all zeros — deriving the percentile bands from
  the raw measures stays necessary.
* `search2` (unrouted) now matches `legal_name` only. `OR dba_name LIKE ?`
  cannot use an index and took ~5 minutes; ordering through `idx_dba` is no
  better because `dba_name` is null on most rows. Restoring it wants FULLTEXT.

### Measured

| | Before (dollar_traq) | Now (carrier) |
|---|---|---|
| DOT / MC / phone / email search | ~2.1s | 0.8-1.7s |
| Name search, count cached | ~1.1s | ~1.5s |
| Name search, cold count | ~7.5s | ~12s (4.48M rows, not 2.07M) |
| Carrier profile | ~15.5s | ~17s |


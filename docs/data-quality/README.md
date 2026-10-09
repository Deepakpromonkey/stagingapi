# Insurance filing dates to review

`insurance-future-dates-2026-10-07.csv` lists the 97 insurance filing rows in
the carrier warehouse with an impossible future date, all from the one-time
bulk CSV load of 2026-08-10 (the daily Motus sync did not write any of them).

They are not one kind of mistake, so they must not be fixed with one UPDATE:

- 36 are two-digit years pushed a century forward on old dockets
  (effective 2088-05-24, cancelled 2001-10-01 is really 1988). Subtracting
  100 years agrees with the row's other date. Pre-marked `approved=yes`.
- 1 is a garbage year (4196), to be blanked. Pre-marked `approved=yes`.
- 60 are typos, mostly on dockets issued since 2017 (MC1572973, effective
  2032-08-10, is not a 1932 filing), or rows where subtracting 100 years puts
  the cancellation before the policy started. Left blank for a person to
  decide; fill in the suggested_* columns and set `approved=yes`.

The suggested_* columns are what each row would become. Apply only approved
rows, only while the row still holds the value shown, and keep a backup.

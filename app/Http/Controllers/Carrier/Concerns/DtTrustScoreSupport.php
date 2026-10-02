<?php

namespace App\Http\Controllers\Carrier\Concerns;

use App\Support\CarrierBenchmarks;
use App\Support\Fmcsa;
use Carbon\Carbon;

/**
 * The members DtTrustScoreV3 needs from whatever class installs it.
 *
 * DtTrustScoreV3 carries the whole scoring engine but deliberately leans on
 * eight small helpers that already lived on CarrierController — its own
 * docblock names them and says it "will fail loudly at boot if they are
 * removed". That was fine while CarrierController was the only host. Once a
 * second controller needed to score carriers, those eight became the thing
 * standing between the engine and reuse.
 *
 * So they live here, and any class that wants a DT score writes:
 *
 *     use DtTrustScoreV3, DtTrustScoreSupport;
 *
 * NOTE: CarrierController still defines its own copies of all eight and is
 * deliberately left untouched. It is 5,700 lines of working, live profile
 * and risk-factor code, and the carrier database it reads is not reachable
 * from every environment, so a refactor there could not be verified end to
 * end at the time this was written. Switching it to this trait — delete its
 * eight members, add DtTrustScoreSupport to its `use` — is a safe follow-up
 * for whoever can run the profile page against a live carrier DB, and would
 * leave exactly one definition of each.
 *
 * Everything here is pure or delegates to CarrierBenchmarks, which is itself
 * the shared source of truth for national cut-points — so the numbers that
 * actually drive scoring are single-sourced regardless.
 */
trait DtTrustScoreSupport
{
    /** The five SMS BASICs the safety rules are written against. */
    private const SMS_BASICS = [
        'unsafe_driv',
        'hos_driv',
        'driv_fit',
        'contr_subst',
        'veh_maint',
    ];

    /**
     * Address fragments that signal a mail drop rather than a real yard.
     * Used by the identity rules to spot a carrier with no physical premises.
     */
    private const MAIL_DROP_PATTERNS = [
        'UPS STORE', 'REGUS', 'WEWORK', 'PMB ', 'POSTAL ANNEX',
        'MAIL BOXES ETC', 'MAILBOX', 'REGISTERED AGENT', 'VIRTUAL OFFICE', 'SUITE #',
    ];

    /**
     * Does this filing represent the coverage kind asked for?
     *
     * Matches on the FMCSA form code first and falls back to the description,
     * because the feed fills one or the other depending on vintage.
     */
    private function insuranceFilingMatches($filing, string $kind): bool
    {
        $code = strtoupper(trim((string) ($filing->ins_form_code ?? '')));
        $desc = strtoupper((string) ($filing->ins_type_desc ?? ''));

        return match ($kind) {
            'bipd' => in_array($code, ['91', '91X'], true) || str_starts_with($desc, 'BIPD'),
            'cargo' => $code === '34' || str_contains($desc, 'CARGO'),
            'bond' => in_array($code, ['84', '85'], true)
                || str_contains($desc, 'SURETY')
                || str_contains($desc, 'BOND')
                || str_contains($desc, 'TRUST FUND'),
            default => false,
        };
    }

    /** Does the carrier hold an uncancelled filing of this coverage kind? */
    private function hasInsuranceFiling($carrier, string $kind): bool
    {
        return $carrier->insuranceFilings->contains(function ($filing) use ($kind) {

            if (! $this->insuranceFilingMatches($filing, $kind)) {
                return false;
            }

            if (empty($filing->cancl_effective_date)) {
                return true;
            }

            return Fmcsa::date($filing->cancl_effective_date)?->isFuture() ?? false;
        });
    }

    /**
     * Per-request memos for the two national lookups below.
     *
     * Both are read from the cache store, and scoring a page of carriers asks
     * for them once per carrier - three cache reads each time, for values that
     * are national constants and cannot change midway through a request. That
     * is free when the cache is in-process and is not when it is not: on a
     * `database` store the reads are round trips, and a ten-carrier page spent
     * thirty of them re-fetching two identical answers.
     *
     * A plain property is the right scope here: the trait is installed on a
     * controller, and a controller instance lives for exactly one request, so
     * the memo is born and dies with the request that filled it. Nothing is
     * shared between requests, and the refresh command keeps writing to the
     * cache as before - the next request picks up whatever it wrote.
     *
     * null means "not looked up yet", which is why these are not initialised
     * to []: an empty array is a legitimate cached value.
     */
    private ?array $dtBenchmarkMemo = null;

    private ?array $dtSmsCutMemo = null;

    /**
     * National 50th / 75th / 90th cut-points for each BASIC measure.
     *
     * The Motus feed carries no `*_pct` column, so the percentile bands the
     * scoring is written against are rebuilt from the raw measures. Computing
     * them is a five-way window sort over the whole SMS table (~12s), so it is
     * refreshed by `carrier:refresh-benchmarks` and only read here.
     */
    private function smsPercentiles(): array
    {
        return $this->dtSmsCutMemo ??= CarrierBenchmarks::smsCuts();
    }

    /** National benchmarks, plus the p90 measure for each BASIC. */
    private function benchmarks(): array
    {
        if ($this->dtBenchmarkMemo !== null) {
            return $this->dtBenchmarkMemo;
        }

        $b = CarrierBenchmarks::all();

        $cuts = $this->smsPercentiles();

        foreach (self::SMS_BASICS as $basic) {
            $b["p90_{$basic}_measure"] = $cuts[$basic][90] > 0 ? $cuts[$basic][90] : null;
        }

        return $this->dtBenchmarkMemo = $b;
    }

    private function getGrade($score)
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }

    /**
     * FMCSA writes dates as '01-JUN-74', which Carbon cannot read on its own
     * and which has no century. Anything later than the current two-digit
     * year is read as 19xx — a 1974 add date is real, a 2074 one is not.
     */
    private function parseFmcsaDate(?string $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            // FMCSA format: 01-JUN-74
            if (preg_match('/^\d{2}-[A-Z]{3}-\d{2}$/', strtoupper($value))) {
                $year = substr($value, -2);
                $century = $year > date('y') ? '19' : '20';
                $fixed = substr($value, 0, -2).$century.$year;

                return Carbon::createFromFormat('d-M-Y', strtoupper($fixed));
            }

            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

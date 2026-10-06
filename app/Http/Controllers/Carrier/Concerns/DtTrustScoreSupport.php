<?php

namespace App\Http\Controllers\Carrier\Concerns;

use App\Support\CarrierBenchmarks;
use App\Support\Fmcsa;
use Carbon\Carbon;

/**
 * The members DtTrustScoreV3 needs from whatever class installs it.
 *
 * App\Services\DtScore\DtScore is the one class that installs it; call
 * DtScore::for() rather than composing these traits anywhere else.
 *
 * Everything here is pure or delegates to CarrierBenchmarks, the shared
 * source of truth for national cut-points.
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

    /** Letter grade for a 0-100 score, from dtscore.grades. */
    private function getGrade($score)
    {
        foreach (config('dtscore.grades') as $grade => $min) {

            if ($score >= $min) {
                return $grade;
            }

        }

        return 'F';
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

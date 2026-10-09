<?php

namespace App\Services\Carrier;

use App\Models\Carriers\Carrier;
use App\Services\DtScore\DtScore;
use App\Support\Fmcsa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DT scores for search-style screens: the background job that scores a
 * search page (ScoreCarrierSearchPage) and the blocklist's carrier cards.
 *
 * Every score comes from App\Services\DtScore\DtScore — the one calculator
 * the carrier profile, search and shortlist use, reading its numbers from
 * config/dtscore.php — so this class only adds what these screens need on
 * top: a statement budget for the carrier host and the card fields shown
 * next to the score.
 */
class DtSearchScoringService
{
    private const CONN = 'external_db';

    /**
     * Per-statement budget for the scoring queries specifically.
     *
     * Deliberately well under QUERY_TIMEOUT_MS: a page of results that comes
     * back without scores is a far better outcome than one that arrives late
     * because an enrichment step went looking for them.
     */
    private const SCORE_TIMEOUT_MS = 8000;

    private const QUERY_TIMEOUT_MS = 25000;

    private function conn()
    {
        return DB::connection(self::CONN);
    }

    private function setStatementTimeout(int $ms): void
    {
        try {
            $this->conn()->statement('SET SESSION MAX_EXECUTION_TIME = '.(int) $ms);
        } catch (\Throwable) {
        }
    }

    /** The letter grade the profile shows for a score. */
    public function gradeFor(int $score): string
    {
        return DtScore::grade($score);
    }

    /**
     * Calculate (and cache) scores for a page of carriers, keyed by DOT.
     *
     * @param  array<int, string>  $dots
     * @return array<string, int>
     */
    public function computePageScores(array $dots): array
    {
        $this->setStatementTimeout(self::SCORE_TIMEOUT_MS);

        try {
            $start = microtime(true);

            $scores = DtScore::many($dots);

            Log::info('[CarrierSearch] dt score ('.count($dots).' carriers) took '.round((microtime(true) - $start) * 1000, 1).'ms');

            return $scores;
        } finally {
            $this->setStatementTimeout(self::QUERY_TIMEOUT_MS);
        }
    }

    /**
     * Card fields for a short, bounded list of carriers keyed by internal
     * id — active authority, insurance, a DT score and the risk pill. Runs
     * synchronously, so it is for lists in the tens (the blocklist), not a
     * live search page.
     *
     * Never throws: a failure means blank enrichment fields, not a 500.
     *
     * @param  array<int, int>  $carrierIds
     * @return array<int, array> keyed by carrier id
     */
    public function enrichCarriers(array $carrierIds): array
    {
        if (empty($carrierIds)) {
            return [];
        }

        $this->setStatementTimeout(self::QUERY_TIMEOUT_MS);

        try {
            // Only what the card itself reads; DtScore loads what scoring needs.
            $carriers = Carrier::whereIn('id', $carrierIds)
                ->with(['carrierDetail', 'authority', 'insuranceFilings'])
                ->get();
        } catch (\Throwable $e) {
            Log::warning('[CarrierBlocked] carrier load failed: '.$e->getMessage());

            return [];
        }

        if ($carriers->isEmpty()) {
            return [];
        }

        $scores = [];

        $this->setStatementTimeout(self::SCORE_TIMEOUT_MS);

        try {
            $scores = DtScore::manyWithStatus($carriers->pluck('dot_number')->all());
        } catch (\Throwable $e) {
            Log::warning('[CarrierBlocked] DT scoring failed: '.$e->getMessage());
        } finally {
            $this->setStatementTimeout(self::QUERY_TIMEOUT_MS);
        }

        $out = [];

        foreach ($carriers as $carrier) {
            $out[$carrier->id] = $this->enrichOne($carrier, $scores[(string) $carrier->dot_number] ?? null);
        }

        return $out;
    }

    /**
     * One carrier's card fields.
     *
     * @param  array{score: int, status: ?string}|null  $dtScore
     */
    private function enrichOne(Carrier $carrier, ?array $dtScore): array
    {
        $auth = $carrier->authority;

        // Same definition the carrier profile uses for both these fields - a
        // carrier with any live authority type reads as both "active" and
        // "verified" together, on purpose.
        $authorityActive = Fmcsa::isActive($auth?->common_stat)
            || Fmcsa::isActive($auth?->contract_stat)
            || Fmcsa::isActive($auth?->broker_stat);

        $insuranceCurrent = false;

        try {
            $insuranceCurrent = DtScore::hasLiveInsurance($carrier, 'bipd');
        } catch (\Throwable $e) {
            Log::warning("[CarrierBlocked] insurance check failed for DOT {$carrier->dot_number}: ".$e->getMessage());
        }

        return [
            'mc_number' => $auth?->docket_number,
            'duns' => $carrier->carrierDetail?->dun_bradstreet_no,
            'active_authority' => $authorityActive,
            'authority_verified' => $authorityActive,
            'insurance_current' => $insuranceCurrent,
            'dt_score' => $dtScore['score'] ?? null,
            'risk_level' => $this->riskLevelFor($dtScore['status'] ?? null),
            'dt_band' => $dtScore['band'] ?? null,
            'dt_needs_manual_review' => (bool) ($dtScore['needs_manual_review'] ?? false),
        ];
    }

    /**
     * The Chrome extension's hover card for one DOT - who the carrier is
     * and whatever DT score is already cached.
     *
     * Only identity, on purpose. The card's reliability / risk factors come
     * from the profile's own GET /carriers/{dot}/risk, which the extension
     * calls alongside this - so the card can never disagree with the
     * profile. (It used to carry its own authority / insurance flags; the
     * insurance one was a simpler "any BIPD filing on record" check than the
     * profile's "BIPD at or above the federal minimum", and the two did
     * disagree.)
     *
     * Deliberately light, unlike enrichCarriers() above: one small relation
     * and no engine run. The extension calls this on hover, so it
     * has to answer in well under a second; a missing score is filled by the
     * same background job the search page uses (the caller dispatches it on
     * a cache miss), read through DtScore::cached() like search is, so the number
     * a broker sees on someone else's website is the number they see in
     * DollarTraq.
     *
     * Returns null when no carrier has this DOT.
     */
    public function quickCard(int $dot): ?array
    {
        // v2: the cached shape changed when the authority / insurance
        // flags were dropped - see the docblock.
        $cardKey = 'ext:card:v2:'.$dot;

        $facts = Cache::get($cardKey);

        /*
        | A broker on a load board hovers the same handful of DOTs over and
        | over, and the census behind these facts reloads daily at most - so
        | they are kept for half an hour rather than re-read from the carrier
        | database on every hover. A DOT with no carrier is remembered too
        | (as false, for less time), so hovering a number that only looks
        | like a DOT does not go back to the database each time. The score is
        | deliberately NOT part of this: it lives under its own key, which
        | the background job fills, and is read fresh on every call.
        */
        if ($facts === null) {
            $facts = $this->quickCardFacts($dot) ?? false;

            Cache::put($cardKey, $facts, now()->addMinutes($facts === false ? 10 : 30));
        }

        if ($facts === false) {
            return null;
        }

        // Through DtScore, never by key: the score's cache key carries the
        // scoring config's fingerprint, so a hand-built key reads a score
        // nobody writes any more.
        return $facts + [
            'dt_score' => DtScore::cached((string) $dot),
        ];
    }

    /** The cacheable part of quickCard() - everything but the score. */
    private function quickCardFacts(int $dot): ?array
    {
        $carrier = Carrier::where('dot_number', $dot)
            ->select(['id', 'dot_number', 'legal_name', 'dba_name', 'phy_city', 'phy_state'])
            ->with('authority') // for the MC number
            ->first();

        if (! $carrier) {
            return null;
        }

        return [
            'carrier_id' => $carrier->id,
            'dot_number' => (string) $carrier->dot_number,
            'legal_name' => $carrier->legal_name,
            'dba_name' => $carrier->dba_name,
            'mc_number' => $carrier->authority?->docket_number,
            'city' => $carrier->phy_city,
            'state' => $carrier->phy_state,
        ];
    }

    /**
     * The engine's own vocabulary (Approved / Review / High Risk /
     * Rejected), relabelled for the risk pill the card shows.
     */
    private function riskLevelFor(?string $status): ?string
    {
        return match ($status) {
            'Approved' => 'Low',
            'Review' => 'Medium',
            'High Risk', 'Rejected' => 'High',
            default => null,
        };
    }
}

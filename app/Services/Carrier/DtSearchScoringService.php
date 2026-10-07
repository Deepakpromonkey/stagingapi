<?php

namespace App\Services\Carrier;

use App\Models\Carriers\Carrier;
use App\Services\DtScore\DtScore;
use App\Support\Fmcsa;
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

<?php

namespace App\Services\Drayage;

use App\Http\Resources\CarrierConnectRequestResource;
use App\Jobs\ScoreCarrierSearchPage;
use App\Models\CarrierConnectRequest;
use App\Models\Carriers\Carrier;
use App\Models\Carriers\Inspection;
use App\Services\DtScore\DtScore;
use App\Support\Fmcsa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What DollarTraq already knows about a drayage carrier, matched by USDOT.
 *
 * Read-only throughout. The FMCSA side reads the carrier views through the
 * same models the carrier profile uses; the Trust Score comes from the same
 * cache key and the same background job as Find New Partner, so a score
 * shown here, on the search page and on the profile can never disagree; and
 * onboarding state is the broker's own CarrierConnectRequest, derived by the
 * same resource the onboarding list uses. Nothing is written - drayage data
 * never goes into MySQL.
 *
 * Every lookup is batched per page (one query for the lot, not one per
 * carrier) and degrades to null rather than failing the request: the
 * directory answer is correct without enrichment.
 */
class DrayageEnrichmentService
{
    private const CONN = 'external_db';

    /** Per-statement budget on the carrier host, well under a page's patience. */
    private const STATEMENT_TIMEOUT_MS = 5000;


    /**
     * USDOT => whether the carrier exists in DollarTraq's carrier views.
     *
     * Shares Carrier::resolveIdFromRowId()'s cache key, which already holds
     * this fact for every carrier anyone has opened; only the rest are asked
     * of the carrier host, in one query. Positive answers are cached, misses
     * are not - a carrier added by the next FMCSA load must show up.
     *
     * @param  list<string>  $dots
     * @return array<string, bool|null> null when the lookup failed
     */
    public function inDollarTraq(array $dots): array
    {
        $dots = array_values(array_unique(array_filter($dots, fn ($d) => is_string($d) && ctype_digit($d))));
        $known = [];
        $ask = [];

        foreach ($dots as $dot) {
            Cache::has('carrier:rowid:'.$dot) ? $known[$dot] = true : $ask[] = $dot;
        }

        if ($ask === []) {
            return $known;
        }

        try {
            $found = $this->withTimeout(fn () => Carrier::whereIn('dot_number', array_map('intval', $ask))
                ->pluck('dot_number')
                ->map(fn ($d) => (string) $d)
                ->all());
        } catch (\Throwable $e) {
            Log::channel('drayage')->warning('Drayage in_dollartraq lookup failed', ['error' => $e->getMessage()]);

            return $known + array_fill_keys($ask, null);
        }

        foreach ($ask as $dot) {
            $exists = in_array($dot, $found, true);
            $known[$dot] = $exists;

            if ($exists) {
                Cache::put('carrier:rowid:'.$dot, (int) $dot, now()->addHours(12));
            }
        }

        return $known;
    }

    /**
     * USDOT => the company's latest onboarding request for that carrier.
     *
     * @param  list<string>  $dots
     * @return array<string, array>
     */
    public function onboarding(?int $companyId, array $dots): array
    {
        $dots = array_values(array_unique(array_filter($dots)));

        if (! $companyId || $dots === []) {
            return [];
        }

        $requests = CarrierConnectRequest::forCompany($companyId)
            ->whereIn('carrier_dot_number', $dots)
            ->latest()
            ->orderByDesc('id')
            ->get()
            ->unique('carrier_dot_number');

        $out = [];

        foreach ($requests as $request) {
            $payload = (new CarrierConnectRequestResource($request))->resolve();

            $out[(string) $request->carrier_dot_number] = [
                'uuid' => $request->uuid,
                'status' => $payload['status'] ?? $request->status,
                'stage' => $payload['stage'] ?? null,
                'stage_label' => $payload['stage_label'] ?? null,
                'steps_completed' => $payload['steps_completed'] ?? null,
                'steps_total' => $payload['steps_total'] ?? null,
                'sent_on' => $request->sent_on?->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * USDOT => Trust Score, from the shared score cache. A carrier with no
     * cached score is queued for the same background scoring Find New
     * Partner uses and reported as pending; GET /carrier/scores?dots[]=...
     * returns it once ready. Never computed on the request path.
     *
     * @param  list<string>  $dots
     * @return array<string, array{score: int|null, grade: string|null, status: string}>
     */
    public function trustScores(array $dots): array
    {
        $dots = array_values(array_unique(array_filter($dots)));
        $out = [];
        $pending = [];

        $scores = DtScore::cachedMany($dots);

        foreach ($dots as $dot) {
            $cached = $scores[(string) $dot] ?? null;

            if ($cached !== null) {
                $out[$dot] = ['score' => $cached, 'grade' => DtScore::grade($cached), 'status' => 'ready'];
            } else {
                $out[$dot] = ['score' => null, 'grade' => null, 'status' => 'pending'];
                $pending[] = $dot;
            }
        }

        $this->queueScoring($pending);

        return $out;
    }

    /**
     * Authority, operating status, out-of-service orders, insurance on file
     * and an inspection summary for one carrier - detail view only.
     */
    public function fmcsa(string $dot): ?array
    {
        if (! ctype_digit($dot)) {
            return null;
        }

        try {
            return $this->withTimeout(function () use ($dot) {
                $carrier = Carrier::where('dot_number', (int) $dot)
                    ->with(['authority', 'carrierDetail', 'smsMeasures', 'oosOrders', 'insuranceFilings'])
                    ->first();

                if (! $carrier) {
                    return null;
                }

                $auth = $carrier->authority;
                $sms = $carrier->smsMeasures;

                $activeOos = $carrier->oosOrders->filter(fn ($o) => $o->rescind_date === null)->values();

                $liveFilings = $carrier->insuranceFilings
                    ->filter(fn ($f) => $f->cancl_effective_date === null || $f->cancl_effective_date->isFuture())
                    ->values();

                $lastInspection = Inspection::where('dot_number', (int) $dot)
                    ->pluck('insp_date')
                    ->map(fn ($d) => Fmcsa::date($d))
                    ->filter()
                    ->max();

                $pct = fn ($part, $whole) => (int) $whole > 0 ? round((int) $part / (int) $whole * 100, 1) : null;

                return [
                    'dot_number' => (string) $carrier->dot_number,
                    'legal_name' => $carrier->legal_name,
                    'dba_name' => $carrier->dba_name,
                    'operating_status' => match (strtoupper((string) $carrier->carrierDetail?->status_code)) {
                        'A' => 'active',
                        'I' => 'inactive',
                        '' => null,
                        default => strtolower((string) $carrier->carrierDetail?->status_code),
                    },
                    'authority' => [
                        'docket_number' => $auth?->docket_number,
                        'common' => $auth ? Fmcsa::isActive($auth->common_stat) : null,
                        'contract' => $auth ? Fmcsa::isActive($auth->contract_stat) : null,
                        'broker' => $auth ? Fmcsa::isActive($auth->broker_stat) : null,
                        'any_active' => $auth ? (Fmcsa::isActive($auth->common_stat) || Fmcsa::isActive($auth->contract_stat) || Fmcsa::isActive($auth->broker_stat)) : false,
                        'revocation_pending' => $auth ? (bool) ($auth->common_rev_pend || $auth->contract_rev_pend || $auth->broker_rev_pend) : null,
                    ],
                    'out_of_service' => [
                        'active' => $activeOos->isNotEmpty(),
                        'orders' => $activeOos->map(fn ($o) => [
                            'date' => $o->oos_date?->toDateString(),
                            'reason' => $o->oos_reason,
                        ])->all(),
                    ],
                    'insurance' => [
                        'bipd_on_file' => $auth ? Fmcsa::onFile($auth->bipd_file) : null,
                        'cargo_on_file' => $auth ? Fmcsa::onFile($auth->cargo_file) : null,
                        'bond_on_file' => $auth ? Fmcsa::onFile($auth->bond_file) : null,
                        'filings' => $liveFilings->map(fn ($f) => [
                            'type' => $f->ins_type_desc,
                            'insurer' => $f->name_company,
                            'effective_date' => $f->effective_date?->toDateString(),
                            // Stored in thousands, as everywhere in this feed.
                            'max_coverage' => $f->max_cov_amount !== null ? (int) round((float) $f->max_cov_amount * 1000) : null,
                        ])->all(),
                    ],
                    'inspections' => [
                        'total_24mo' => $sms ? (int) $sms->insp_total : null,
                        'driver_oos_pct' => $sms ? $pct($sms->driver_oos_insp_total, $sms->driver_insp_total) : null,
                        'vehicle_oos_pct' => $sms ? $pct($sms->vehicle_oos_insp_total, $sms->vehicle_insp_total) : null,
                        'last_inspection_date' => $lastInspection?->toDateString(),
                    ],
                ];
            });
        } catch (\Throwable $e) {
            Log::channel('drayage')->warning('Drayage FMCSA enrichment failed', ['dot' => $dot, 'error' => $e->getMessage()]);

            return ['error' => 'FMCSA data is unavailable right now.'];
        }
    }

    /**
     * What a broker can do next with this carrier, for the Carrier Search
     * card: open its DollarTraq profile, follow an onboarding already under
     * way, or invite it through the existing carrier-connect flow.
     */
    public function actions(?string $dot, ?bool $inDollarTraq, ?array $onboarding, array $record): array
    {
        if ($onboarding !== null) {
            $next = 'view_onboarding';
        } elseif ($dot !== null && $inDollarTraq) {
            $next = 'invite';
        } else {
            $next = 'unavailable';
        }

        $alternate = $record['dispatch_email'] ?? (($record['emails'] ?? [])[0] ?? null);

        return [
            'next_action' => $next,
            'profile' => $dot !== null && $inDollarTraq ? [
                'dot_number' => $dot,
                'trust_score_endpoint' => "GET /api/v1/carrier/{$dot}/trust-score",
            ] : null,
            // The existing invite, unchanged. With email_option=fmcsa it goes
            // to the FMCSA-registered address; an alternate (the directory's
            // dispatch email) waits for the carrier's approval first.
            'invite' => $next === 'invite' ? [
                'method' => 'POST',
                'endpoint' => '/api/v1/carrier-connect',
                'permission' => 'send-invitation-approved-carriers',
                'body' => ['row_id' => $dot, 'email_option' => 'fmcsa'],
                'alternate_body' => $alternate ? ['row_id' => $dot, 'email_option' => 'alternate', 'email' => $alternate] : null,
            ] : null,
            'reason' => match (true) {
                $next !== 'unavailable' => null,
                $dot === null => 'No USDOT number in the directory profile, so it cannot be matched to an FMCSA carrier.',
                $inDollarTraq === null => 'The carrier database could not be reached to check this USDOT.',
                default => 'This USDOT is not in the FMCSA census DollarTraq uses.',
            },
        ];
    }

    /**
     * Same dedupe and dispatch as AdvancedCarrierSearchController: one
     * background scoring run per DOT per two minutes, after the response.
     */
    private function queueScoring(array $dots): void
    {
        $toDispatch = array_values(array_filter(
            $dots,
            fn ($dot) => Cache::add('dt:score:queued:'.$dot, true, now()->addMinutes(2))
        ));

        if ($toDispatch === []) {
            return;
        }

        try {
            ScoreCarrierSearchPage::dispatch($toDispatch)->afterResponse();
        } catch (\Throwable $e) {
            Log::channel('drayage')->warning('Drayage background scoring dispatch failed', ['error' => $e->getMessage()]);
        }
    }

    private function withTimeout(\Closure $fn): mixed
    {
        $conn = DB::connection(self::CONN);

        try {
            $conn->statement('SET SESSION MAX_EXECUTION_TIME = '.self::STATEMENT_TIMEOUT_MS);
        } catch (\Throwable) {
        }

        try {
            return $fn();
        } finally {
            try {
                $conn->statement('SET SESSION MAX_EXECUTION_TIME = 25000');
            } catch (\Throwable) {
            }
        }
    }
}

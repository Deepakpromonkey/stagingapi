<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Carriers\Carrier;
use App\Models\CarrierShortlist;
use App\Services\DtScore\DtScore;
use App\Support\Fmcsa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The shortlist card needs the same real signals the advanced search page
 * shows - active authority, insurance, a DT score - and used to fake three
 * of them (see git history: the frontend hardcoded active_authority,
 * authority_verified and insurance_current to constants, and never read
 * dt_score at all, because this endpoint never sent real ones).
 *
 * The DT score comes from DtScore::manyWithStatus() — the same
 * calculator and cached value the search page and the carrier profile use.
 */
class CarrierShortlistController extends Controller
{
    private const CONN = 'external_db';

    /** Statement budget while eager-loading / running the batched stats
     * queries. Restored after scoring so nothing downstream inherits it. */
    private const QUERY_TIMEOUT_MS = 15000;

    private const SCORE_TIMEOUT_MS = 8000;

    /**
     * The carrier's own columns, exactly as the pre-enrichment response
     * already sent them - explicit on purpose, not $carrier->toArray().
     * toArray() on a model with the relations below eager-loaded onto it
     * would dump every crash, inspection and insurance-filing row into the
     * response too; a shortlist card needs none of that.
     */
    private const CARRIER_COLUMNS = [
        'id', 'row_id', 'dot_number', 'legal_name', 'dba_name', 'carrier_operation', 'hm_flag',
        'phy_street', 'phy_city', 'phy_state', 'phy_zip', 'phy_zip5', 'phy_country',
        'mailing_street', 'mailing_city', 'mailing_state', 'mailing_zip', 'mailing_country',
        'telephone', 'fax', 'email_address',
        'mcs150_date', 'mcs150_mileage', 'mcs150_mileage_year', 'add_date',
        'nbr_power_unit', 'driver_total', 'created_at', 'updated_at',
    ];

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

    /**
     * The shortlist belongs to the company, so everyone on the team sees the
     * same carriers regardless of who added them.
     *
     * Paginated - a company's shortlist runs to the thousands in practice,
     * and every row on the page gets a live DT score computed if it isn't
     * already cached, which is too expensive to run over an unbounded list.
     * Only the page actually being shown ever gets scored, same principle
     * as the advanced search page.
     */
    public function index(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $paginator = CarrierShortlist::where('company_id', $request->user()->company_id)
            ->with('user:id,first_name,last_name')
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        $carrierIds = $paginator->getCollection()
            ->pluck('carrier_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $enriched = $this->enrichedCarriers($carrierIds);

        $carriers = $paginator->getCollection()->map(function (CarrierShortlist $entry) use ($enriched) {
            $meta = [
                'shortlisted_by' => $entry->user
                    ? trim($entry->user->first_name.' '.$entry->user->last_name)
                    : null,
                'shortlisted_at' => $entry->created_at?->format('m/d/y'),
            ];

            $carrier = $entry->carrier_id ? ($enriched[$entry->carrier_id] ?? null) : null;

            // The carrier row itself no longer resolves in the feed (rare,
            // but a shortlist entry can outlive one) - the shortlist row
            // still shows, just without carrier detail, rather than the
            // whole page silently losing an entry.
            if ($carrier === null) {
                return $meta + ['id' => $entry->carrier_id];
            }

            return $carrier + $meta;
        })->values();

        return response()->json([
            'status' => 'success',
            'message' => 'Shortlisted carriers retrieved.',
            'data' => $carriers,
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'has_more_pages' => $paginator->hasMorePages(),
        ]);
    }

    /**
     * Full carrier detail for one page of the shortlist, keyed by carrier
     * id - live DT score, real active-authority status, insurance, and
     * everything else the card needs, computed for at most one page's worth
     * of carriers at a time.
     *
     * Never throws: a failure anywhere here means the shortlist page shows
     * carriers with blank enrichment fields rather than a 500. The base
     * identity fields (name, address, phone) are the one thing this
     * endpoint must not fail to show — everything added here is a bonus on
     * top of that.
     *
     * @param  array<int, int>  $carrierIds
     * @return array<int, array>
     */
    private function enrichedCarriers(array $carrierIds): array
    {
        if (empty($carrierIds)) {
            return [];
        }

        try {
            // Only what the card itself reads; DtScore loads what scoring needs.
            $carriers = Carrier::whereIn('id', $carrierIds)
                ->with(['carrierDetail', 'authority', 'insuranceFilings'])
                ->get();
        } catch (\Throwable $e) {
            Log::warning('[CarrierShortlist] carrier load failed: '.$e->getMessage());

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
            Log::warning('[CarrierShortlist] DT scoring failed: '.$e->getMessage());
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
     * One carrier's card fields: identity columns plus authority,
     * insurance, and the DT score DtScore::manyWithStatus() returned.
     *
     * @param  array{score: int, status: ?string}|null  $dtScore
     */
    private function enrichOne(Carrier $carrier, ?array $dtScore): array
    {
        $base = array_intersect_key($carrier->toArray(), array_flip(self::CARRIER_COLUMNS));

        $auth = $carrier->authority;

        // Same definition CarrierController's own profile page uses for
        // both these fields — a carrier with any live authority type reads
        // as both "active" and "verified" together, on purpose.
        $authorityActive = Fmcsa::isActive($auth?->common_stat)
            || Fmcsa::isActive($auth?->contract_stat)
            || Fmcsa::isActive($auth?->broker_stat);

        $insuranceCurrent = false;

        try {
            $insuranceCurrent = DtScore::hasLiveInsurance($carrier, 'bipd');
        } catch (\Throwable $e) {
            Log::warning("[CarrierShortlist] insurance check failed for DOT {$carrier->dot_number}: ".$e->getMessage());
        }

        return $base + [
            'mc_number' => $auth?->docket_number,
            'duns' => $carrier->carrierDetail?->dun_bradstreet_no,
            'safety_rating' => Fmcsa::safetyRating($carrier->carrierDetail?->safety_rating),
            'active_authority' => $authorityActive,
            'authority_verified' => $authorityActive,
            'insurance_current' => $insuranceCurrent,
            'dt_score' => $dtScore['score'] ?? null,
            'risk_level' => $this->riskLevelFor($dtScore['status'] ?? null),
        ];
    }

    /**
     * The engine's own vocabulary (Approved / Review / High Risk /
     * Rejected), relabelled for the risk pill the card shows. No new
     * thresholds invented here - this reads the same status string
     * the DT score engine already decided, just renamed for the UI.
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

    public function store(Request $request)
    {
        $request->validate([
            'row_id' => 'required|string',
        ]);

        // Only the key is needed here, and resolving it is cached — the
        // shortlist itself is local, so the remote carrier lookup was the whole
        // of the delay on this endpoint.
        $carrierId = Carrier::resolveIdFromRowId($request->row_id);

        if (! $carrierId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Carrier not found in system.',
            ], 404);
        }

        CarrierShortlist::updateOrCreate(
            [
                'company_id' => $request->user()->company_id,
                'carrier_id' => $carrierId,
            ],
            [
                'user_id' => $request->user()->id,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Carrier added to shortlist successfully.',
        ]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'row_id' => 'required|string',
        ]);

        // Only the key is needed here, and resolving it is cached — the
        // shortlist itself is local, so the remote carrier lookup was the whole
        // of the delay on this endpoint.
        $carrierId = Carrier::resolveIdFromRowId($request->row_id);

        if (! $carrierId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Carrier not found in system.',
            ], 404);
        }

        CarrierShortlist::where('company_id', $request->user()->company_id)
            ->where('carrier_id', $carrierId)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Carrier removed from shortlist.',
        ]);
    }
}

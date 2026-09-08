<?php

namespace Database\Seeders;

use App\Models\CarrierConnectRequest;
use App\Models\CarrierUser;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo onboardings for the broker-side Carrier Connect page.
 *
 * One row per stage the list can show — invited, part way through each of the
 * six wizard steps, onboarded, ID-check failed, expired and risk flagged — so
 * the tabs, the counters and the progress column all have something to render.
 *
 * Re-runnable: every row it owns is keyed by the SEED- carrier_row_id prefix
 * and cleared first, so `db:seed --class=CarrierConnectRequestSeeder` twice
 * leaves the same twelve rows rather than colliding on the
 * (company_id, carrier_row_id) unique index.
 */
class CarrierConnectRequestSeeder extends Seeder
{
    /** Marks the rows this seeder owns, so a re-run can reclaim them. */
    private const ROW_ID_PREFIX = 'SEED-CONNECT-';

    public function run(): void
    {
        $company = Company::first();

        if (! $company) {
            $this->command->error('No company found — seed a company and user first.');

            return;
        }

        $user = User::where('company_id', $company->id)->first();

        // Only used to hang an agreement off the signed rows; null is fine.
        $agreementId = DB::table('broker_agreement_documents')
            ->where('company_id', $company->id)
            ->value('id');

        $this->clearPreviousRun($company->id);

        $lifetime = (int) config('carrier_connect.request_lifetime_hours', 72);

        foreach ($this->rows($lifetime) as $index => $row) {

            $sentOn = $row['sent_on'];

            $request = CarrierConnectRequest::create(array_merge([
                'uuid' => (string) Str::uuid(),
                'company_id' => $company->id,
                'user_id' => $user?->id,
                'carrier_row_id' => self::ROW_ID_PREFIX.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'token' => Str::random(64),
                'agreement_document_id' => $agreementId,
                'sent_on' => $sentOn,
                'created_at' => $sentOn,
                'updated_at' => $sentOn,
            ], $row));

            // A finished onboarding has provisioned a portal login, and the
            // page reports on it, so the completed row needs a real one.
            if ($request->status === CarrierConnectRequest::STATUS_COMPLETED) {
                $this->attachPortalAccount($request);
            }
        }

        $this->command->info('Seeded '.count($this->rows($lifetime)).' carrier connect requests for company #'.$company->id.'.');
    }

    /**
     * The twelve demo onboardings, one per state worth looking at.
     *
     * Timestamps are relative to now so the expired row stays expired and the
     * rest stay live however long after seeding the page is opened.
     */
    private function rows(int $lifetime): array
    {
        $now = now();

        return [
            // ---- Invited: emailed, never opened the link ------------------
            [
                'carrier_dot_number' => '2201455',
                'carrier_legal_name' => 'BLUE RIDGE FREIGHT LINES LLC',
                'carrier_email' => 'dispatch@blueridgefreight.test',
                'carrier_phone' => '(704) 555-0142',
                'status' => CarrierConnectRequest::STATUS_NEW,
                'sent_on' => $now->copy()->subHours(2),
            ],
            [
                'carrier_dot_number' => '3318907',
                'carrier_legal_name' => 'CASCADE HAULING CO',
                'carrier_email' => 'ops@cascadehauling.test',
                'carrier_phone' => '(503) 555-0188',
                'status' => CarrierConnectRequest::STATUS_NEW,
                'sent_on' => $now->copy()->subHours(20),
            ],

            // ---- In progress: opened the link, nothing verified yet -------
            [
                'carrier_dot_number' => '1904772',
                'carrier_legal_name' => 'MIDWEST LANE CARRIERS INC',
                'carrier_email' => 'admin@midwestlane.test',
                'carrier_phone' => '(312) 555-0119',
                'status' => CarrierConnectRequest::STATUS_NEW,
                'sent_on' => $now->copy()->subHours(6),
                'first_visit_at' => $now->copy()->subHours(5),
            ],

            // ---- Step 1: email verified -----------------------------------
            [
                'carrier_dot_number' => '2740183',
                'carrier_legal_name' => 'SUNBELT TRANSPORT GROUP LLC',
                'carrier_email' => 'billing@sunbelttransport.test',
                'carrier_phone' => '(602) 555-0167',
                'status' => CarrierConnectRequest::STATUS_EMAIL_VERIFIED,
                'sent_on' => $now->copy()->subHours(9),
                'first_visit_at' => $now->copy()->subHours(8),
                'email_verified_at' => $now->copy()->subHours(8),
            ],

            // ---- Step 2: mobile verified ----------------------------------
            [
                'carrier_dot_number' => '3560214',
                'carrier_legal_name' => 'IRONHORSE LOGISTICS LLC',
                'carrier_email' => 'contact@ironhorselogistics.test',
                'carrier_phone' => '(214) 555-0133',
                'status' => CarrierConnectRequest::STATUS_MOBILE_VERIFIED,
                'sent_on' => $now->copy()->subHours(14),
                'first_visit_at' => $now->copy()->subHours(13),
                'email_verified_at' => $now->copy()->subHours(13),
                'mobile_verified_at' => $now->copy()->subHours(12),
            ],

            // ---- ID check still pending, so the wizard shows the hold-up ---
            [
                'carrier_dot_number' => '2988341',
                'carrier_legal_name' => 'GULF COAST DRAYAGE INC',
                'carrier_email' => 'safety@gulfcoastdrayage.test',
                'carrier_phone' => '(713) 555-0175',
                'status' => CarrierConnectRequest::STATUS_MOBILE_VERIFIED,
                'sent_on' => $now->copy()->subHours(16),
                'first_visit_at' => $now->copy()->subHours(15),
                'email_verified_at' => $now->copy()->subHours(15),
                'mobile_verified_at' => $now->copy()->subHours(15),
                'didit_session_id' => 'didit_sess_'.Str::random(18),
                'didit_status' => 'In Review',
                'didit_responded_at' => $now->copy()->subHours(14),
            ],

            // ---- Step 3: identity approved --------------------------------
            [
                'carrier_dot_number' => '3102668',
                'carrier_legal_name' => 'NORTHSTAR CARRIERS LLC',
                'carrier_email' => 'ops@northstarcarriers.test',
                'carrier_phone' => '(651) 555-0192',
                'status' => CarrierConnectRequest::STATUS_ID_VERIFIED,
                'sent_on' => $now->copy()->subHours(22),
                'first_visit_at' => $now->copy()->subHours(21),
                'email_verified_at' => $now->copy()->subHours(21),
                'mobile_verified_at' => $now->copy()->subHours(21),
                'didit_session_id' => 'didit_sess_'.Str::random(18),
                'didit_status' => 'Approved',
                'didit_responded_at' => $now->copy()->subHours(20),
                'didit_registration_ip' => '73.118.24.51',
            ],

            // ---- Identity approved but flagged: VPN / data centre ----------
            [
                'carrier_dot_number' => '3644190',
                'carrier_legal_name' => 'PACIFIC RIM TRUCKING INC',
                'carrier_email' => 'dispatch@pacificrimtrucking.test',
                'carrier_phone' => '(206) 555-0148',
                'status' => CarrierConnectRequest::STATUS_ID_VERIFIED,
                'sent_on' => $now->copy()->subHours(30),
                'first_visit_at' => $now->copy()->subHours(29),
                'email_verified_at' => $now->copy()->subHours(29),
                'mobile_verified_at' => $now->copy()->subHours(29),
                'didit_session_id' => 'didit_sess_'.Str::random(18),
                'didit_status' => 'Approved',
                'didit_responded_at' => $now->copy()->subHours(28),
                'didit_risk_flagged' => true,
                'didit_registration_ip' => '185.220.101.44',
            ],

            // ---- Step 4: bank verified, factoring answered yes -------------
            [
                'carrier_dot_number' => '2455038',
                'carrier_legal_name' => 'GREAT PLAINS EXPRESS LLC',
                'carrier_email' => 'accounting@greatplainsexpress.test',
                'carrier_phone' => '(316) 555-0154',
                'status' => CarrierConnectRequest::STATUS_BANK_VERIFIED,
                'sent_on' => $now->copy()->subHours(34),
                'first_visit_at' => $now->copy()->subHours(33),
                'email_verified_at' => $now->copy()->subHours(33),
                'mobile_verified_at' => $now->copy()->subHours(33),
                'didit_session_id' => 'didit_sess_'.Str::random(18),
                'didit_status' => 'Approved',
                'didit_responded_at' => $now->copy()->subHours(32),
                'stripe_express_account' => 'acct_'.Str::random(16),
                'stripe_verified_at' => $now->copy()->subHours(31),
                'uses_factoring_company' => true,
                'factoring_company_name' => 'Apex Capital Corp',
                'factoring_answered_at' => $now->copy()->subHours(31),
            ],

            // ---- Step 5: questionnaire and documents done, not signed ------
            [
                'carrier_dot_number' => '3877265',
                'carrier_legal_name' => 'REDWOOD FREIGHT SYSTEMS INC',
                'carrier_email' => 'compliance@redwoodfreight.test',
                'carrier_phone' => '(415) 555-0126',
                'status' => CarrierConnectRequest::STATUS_QUESTIONNAIRE_DONE,
                'sent_on' => $now->copy()->subHours(40),
                'first_visit_at' => $now->copy()->subHours(39),
                'email_verified_at' => $now->copy()->subHours(39),
                'mobile_verified_at' => $now->copy()->subHours(39),
                'didit_session_id' => 'didit_sess_'.Str::random(18),
                'didit_status' => 'Approved',
                'didit_responded_at' => $now->copy()->subHours(38),
                'stripe_express_account' => 'acct_'.Str::random(16),
                'stripe_verified_at' => $now->copy()->subHours(37),
                'uses_factoring_company' => false,
                'factoring_answered_at' => $now->copy()->subHours(37),
                'questionnaire_completed_at' => $now->copy()->subHours(36),
                'documents_completed_at' => $now->copy()->subHours(35),
            ],

            // ---- Onboarded: signed, portal login provisioned ---------------
            [
                'carrier_dot_number' => '2033719',
                'carrier_legal_name' => 'ATLANTIC OVERLAND TRANSPORT LLC',
                'carrier_email' => 'owner@atlanticoverland.test',
                'carrier_phone' => '(904) 555-0171',
                'status' => CarrierConnectRequest::STATUS_COMPLETED,
                'sent_on' => $now->copy()->subHours(50),
                'first_visit_at' => $now->copy()->subHours(49),
                'email_verified_at' => $now->copy()->subHours(49),
                'mobile_verified_at' => $now->copy()->subHours(49),
                'didit_session_id' => 'didit_sess_'.Str::random(18),
                'didit_status' => 'Approved',
                'didit_responded_at' => $now->copy()->subHours(48),
                'didit_registration_ip' => '68.44.201.7',
                'stripe_express_account' => 'acct_'.Str::random(16),
                'stripe_verified_at' => $now->copy()->subHours(47),
                'uses_factoring_company' => false,
                'factoring_answered_at' => $now->copy()->subHours(47),
                'questionnaire_completed_at' => $now->copy()->subHours(46),
                'documents_completed_at' => $now->copy()->subHours(46),
                'signed_at' => $now->copy()->subHours(45),
            ],

            // ---- ID check failed ------------------------------------------
            [
                'carrier_dot_number' => '3491082',
                'carrier_legal_name' => 'SUMMIT VALLEY TRUCKING LLC',
                'carrier_email' => 'info@summitvalleytrucking.test',
                'carrier_phone' => '(801) 555-0139',
                'status' => CarrierConnectRequest::STATUS_MOBILE_VERIFIED,
                'sent_on' => $now->copy()->subHours(28),
                'first_visit_at' => $now->copy()->subHours(27),
                'email_verified_at' => $now->copy()->subHours(27),
                'mobile_verified_at' => $now->copy()->subHours(27),
                'didit_session_id' => 'didit_sess_'.Str::random(18),
                'didit_status' => 'Declined',
                'didit_responded_at' => $now->copy()->subHours(26),
                'didit_registration_ip' => '45.83.12.190',
            ],

            // ---- Expired: sent past the invitation lifetime ----------------
            [
                'carrier_dot_number' => '2670541',
                'carrier_legal_name' => 'LONE STAR CARTAGE INC',
                'carrier_email' => 'dispatch@lonestarcartage.test',
                'carrier_phone' => '(210) 555-0163',
                'status' => CarrierConnectRequest::STATUS_NEW,
                'sent_on' => $now->copy()->subHours($lifetime + 24),
            ],
        ];
    }

    /**
     * Gives a completed onboarding the carrier portal login it would have been
     * provisioned on signing, reusing the account if the address already exists.
     */
    private function attachPortalAccount(CarrierConnectRequest $request): void
    {
        $carrierUser = CarrierUser::withTrashed()
            ->firstOrCreate(
                ['email' => $request->carrier_email],
                [
                    'uuid' => (string) Str::uuid(),
                    'password' => 'Password@123',
                    'must_change_password' => true,
                    'legal_name' => $request->carrier_legal_name,
                    'dot_number' => $request->carrier_dot_number,
                    'phone' => $request->carrier_phone,
                    'status' => true,
                ]
            );

        $request->update([
            'carrier_user_id' => $carrierUser->id,
            'portal_account_provisioned_at' => $request->signed_at,
            'portal_account_email' => $carrierUser->email,
        ]);
    }

    /**
     * Drops the previous run's rows. The portal logins go too, otherwise the
     * unique email would hand the next run a stale account.
     */
    private function clearPreviousRun(int $companyId): void
    {
        $previous = CarrierConnectRequest::where('company_id', $companyId)
            ->where('carrier_row_id', 'like', self::ROW_ID_PREFIX.'%')
            ->get();

        if ($previous->isEmpty()) {
            return;
        }

        $carrierUserIds = $previous->pluck('carrier_user_id')->filter()->all();

        CarrierConnectRequest::whereIn('id', $previous->pluck('id'))->delete();

        if ($carrierUserIds) {
            CarrierUser::whereIn('id', $carrierUserIds)->forceDelete();
        }
    }
}

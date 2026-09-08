<?php

namespace App\Services\Carrier;

use App\Models\CarrierConnectDocument;
use App\Models\CarrierConnectRequest;
use App\Models\Carriers\Carrier as FmcsaCarrier;
use App\Models\Carriers\CarrierAuthority;
use App\Models\CarrierUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The carrier portal's "My Profile" screen: who the carrier is, and how much
 * of their onboarding paperwork is actually done.
 *
 * Completeness is measured across every broker the carrier has onboarded with,
 * not per onboarding — a W-9 handed to one broker is a W-9 on file. The carrier
 * should never be told a step is outstanding when they have already done it
 * somewhere in the system.
 */
class CarrierPortalProfileService
{
    /**
     * The checklist, in the order the portal shows it. Key => label.
     */
    public const CHECKLIST = [
        'company_authority_info' => 'Company & authority info',
        'insurance_coi' => 'Insurance / COI uploaded',
        'w9_on_file' => 'W-9 on file',
        'carrier_agreement_signed' => 'Carrier agreement signed',
        'email_verified' => 'Email verified',
        'phone_verified' => 'Phone verified',
        'identity_verified' => 'Identity verified',
        'bank_account_connected' => 'Bank account connected',
    ];

    /** How long an FMCSA lookup is reused before the external DB is asked again. */
    protected const FMCSA_CACHE_MINUTES = 60;

    public function __construct(
        protected CarrierConnectionService $carrierConnectionService
    ) {}

    /**
     * @return array{user: array, company: array, completeness: array, editable_fields: array<int, string>}
     */
    public function for(CarrierUser $carrierUser): array
    {
        $connections = $this->carrierConnectionService->forCarrier($carrierUser);

        $dotNumber = $carrierUser->carrierCompany?->dot_number ?: $carrierUser->dot_number;

        $fmcsa = $this->fmcsaSnapshot($dotNumber);

        return [
            'user' => $this->user($carrierUser),
            'company' => $this->company($carrierUser, $fmcsa),
            'completeness' => $this->completeness($carrierUser, $connections),

            // What the portal is allowed to render as an input on this screen.
            // Everything absent from this list is FMCSA or onboarding data the
            // carrier cannot correct from here — the portal greys it out and
            // the API has no route to write it either.
            'editable_fields' => ['profile_image'],
        ];
    }

    /**
     * The signed-in person, as the profile header shows them.
     */
    protected function user(CarrierUser $carrierUser): array
    {
        return [
            'uuid' => $carrierUser->uuid,
            'display_name' => $carrierUser->displayName(),
            'first_name' => $carrierUser->first_name,
            'last_name' => $carrierUser->last_name,
            'email' => $carrierUser->email,
            'phone' => $carrierUser->phone,
            'profile_image' => $carrierUser->profileImageUrl(),
            'is_owner' => (bool) $carrierUser->is_owner,
            'role' => $carrierUser->role()?->name,
            'two_factor_enabled' => (bool) $carrierUser->two_factor_enabled,
            'last_password_changed_at' => $carrierUser->last_password_changed_at?->toIso8601String(),
        ];
    }

    /**
     * Company card. The carrier's own record is authoritative for identity;
     * fleet size and domicile only exist in the FMCSA data.
     */
    protected function company(CarrierUser $carrierUser, array $fmcsa): array
    {
        $company = $carrierUser->carrierCompany;

        return [
            'legal_name' => $company?->legal_name ?: ($carrierUser->legal_name ?: $fmcsa['legal_name']),
            'dba_name' => $fmcsa['dba_name'],
            'dot_number' => $company?->dot_number ?: $carrierUser->dot_number,
            'mc_number' => $fmcsa['mc_number'],

            'fleet' => [
                'power_units' => $fmcsa['power_units'],
                'drivers' => $fmcsa['drivers'],
            ],

            'domicile' => [
                'city' => $fmcsa['city'],
                'state' => $fmcsa['state'],
            ],

            // The portal account's own standing, not FMCSA operating status —
            // this is what decides whether these logins work.
            'status' => ($company?->status ?? $carrierUser->status) ? 'active' : 'inactive',

            'phone' => $company?->phone ?: $carrierUser->phone,
            'email' => $carrierUser->email,
        ];
    }

    /**
     * @param  Collection<int, CarrierConnectRequest>  $connections
     */
    protected function completeness(CarrierUser $carrierUser, Collection $connections): array
    {
        $documentTypes = $connections->isEmpty()
            ? collect()
            : CarrierConnectDocument::whereIn('carrier_connect_request_id', $connections->pluck('id'))
                ->pluck('type')
                ->unique();

        $company = $carrierUser->carrierCompany;

        $done = [
            'company_authority_info' => filled($company?->legal_name ?: $carrierUser->legal_name)
                && filled($company?->dot_number ?: $carrierUser->dot_number),

            'insurance_coi' => $documentTypes->contains('coi'),

            'w9_on_file' => $documentTypes->contains('w9'),

            'carrier_agreement_signed' => $connections->contains(fn ($request) => $request->signed_at !== null),

            'email_verified' => $carrierUser->email_verified_at !== null
                || $connections->contains(fn ($request) => $request->email_verified_at !== null),

            'phone_verified' => $connections->contains(fn ($request) => $request->mobile_verified_at !== null),

            'identity_verified' => $connections->contains(fn ($request) => $request->didit_status === 'Approved'),

            'bank_account_connected' => $connections->contains(fn ($request) => $request->stripe_verified_at !== null),
        ];

        $items = collect(self::CHECKLIST)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'completed' => (bool) ($done[$key] ?? false),
            ])
            ->values();

        $completed = $items->where('completed', true)->count();

        $total = $items->count();

        return [
            // Floored, never rounded up: a carrier should not be shown 63%
            // when five of eight steps are done.
            'percentage' => $total ? (int) floor($completed / $total * 100) : 0,
            'completed_steps' => $completed,
            'total_steps' => $total,
            'items' => $items->all(),
        ];
    }

    /**
     * FMCSA identity for a DOT number, from the carrier database on EC2.
     *
     * Best effort by design: that database is remote, and the profile screen
     * must still render its checklist when it cannot be reached.
     *
     * @return array{legal_name: ?string, dba_name: ?string, mc_number: ?string, power_units: ?int, drivers: ?int, city: ?string, state: ?string}
     */
    protected function fmcsaSnapshot(?string $dotNumber): array
    {
        $empty = [
            'legal_name' => null,
            'dba_name' => null,
            'mc_number' => null,
            'power_units' => null,
            'drivers' => null,
            'city' => null,
            'state' => null,
        ];

        if (! $dotNumber) {
            return $empty;
        }

        $key = "carrier-portal:fmcsa:{$dotNumber}";

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        try {
            $carrier = FmcsaCarrier::where('dot_number', $dotNumber)->first();

            $authority = CarrierAuthority::where('dot_number', $dotNumber)
                ->whereNotNull('docket_number')
                ->first();

            $snapshot = [
                'legal_name' => $carrier?->legal_name,
                'dba_name' => $carrier?->dba_name,
                'mc_number' => $authority?->docket_number,
                'power_units' => $carrier?->nbr_power_unit,
                'drivers' => $carrier?->driver_total,
                'city' => $carrier?->phy_city,
                'state' => $carrier?->phy_state,
            ];
        } catch (\Throwable $e) {
            // The remote database is unreachable. Not cached — a transient
            // outage must not blank the company card for the next hour.
            Log::warning('FMCSA lookup for carrier portal profile failed', [
                'dot_number' => $dotNumber,
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }

        Cache::put($key, $snapshot, now()->addMinutes(self::FMCSA_CACHE_MINUTES));

        return $snapshot;
    }
}

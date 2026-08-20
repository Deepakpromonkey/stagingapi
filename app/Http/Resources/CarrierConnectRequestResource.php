<?php

namespace App\Http\Resources;

use App\Models\CarrierConnectDocument;
use App\Models\CarrierConnectRequest;
use App\Services\Carrier\CarrierAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The carrier-safe view of an onboarding request. The OTP, the invitation token
 * and the raw Didit payload are never exposed.
 */
class CarrierConnectRequestResource extends JsonResource
{
    /*
    | The onboarding steps, in the order the wizard walks them.
    |
    | Declared here so the API owns the list. The wizard used to keep its own
    | copy, which is how the document upload came to be missing from it: the
    | step was added on this side and the front end was never renumbered.
    */
    private const STEPS = [
        'phone' => 'Phone number',
        'identity' => 'Government ID',
        'bank' => 'Bank account',
        'questionnaire' => 'Broker questions',
        'documents' => 'Documents',
        'agreement' => 'Carrier agreement',
    ];

    /*
    | The two a carrier may move past without completing. The phone check, the
    | questionnaire and the agreement are not skippable: the first is what
    | proves we are talking to the carrier, and the other two are the broker's
    | own requirements.
    */
    private const SKIPPABLE = ['identity', 'bank'];

    public function toArray(Request $request): array
    {
        [$stage, $stageLabel] = $this->stage();

        $steps = $this->steps();

        // Counted over the steps that apply to this carrier, so one paid by a
        // factoring company — who has no bank step — can still reach the end.
        $applicable = collect($steps)->where('applicable', true);

        return [
            'uuid' => $this->uuid,

            'status' => $this->status,

            // Where this sits from the broker's point of view. `status` tracks
            // the furthest step reached; this folds in expiry and a failed ID
            // check, which the raw column cannot express.
            'stage' => $stage,
            'stage_label' => $stageLabel,
            /*
            | The ordered step list the wizard renders. Sent by the API rather
            | than hardcoded in the front end, because a second copy of this
            | list is what let the document upload go missing from the wizard
            | while the counts here still said six.
            */
            'steps' => $steps,

            // Genuinely done. A skip is deliberately not counted here, for the
            // same reason it is kept out of the *_verified flags below.
            'steps_completed' => $applicable->where('completed', true)->count(),

            // Done or deliberately passed over — what a progress bar wants, so
            // that skipping the ID check still moves the carrier forward.
            'steps_settled' => $applicable->where('settled', true)->count(),

            'steps_total' => $applicable->count(),

            'carrier' => [
                'row_id' => $this->carrier_row_id,
                'dot_number' => $this->carrier_dot_number,
                'legal_name' => $this->carrier_legal_name,
                'email' => $this->carrier_email,
                'phone' => $this->carrier_phone,
            ],

            /*
            | An alternate address the broker asked for that is still waiting on
            | the carrier's approval. While this is set, nothing has been sent to
            | it — the broker needs to see that, rather than assume the carrier
            | is sitting on an invitation.
            |
            | The approval token is never exposed here: holding it is what
            | constitutes the carrier's consent.
            */
            // Set when this request was seeded from the carrier's onboarding
            // with an earlier broker, so the wizard can say why the first few
            // steps are already done rather than leaving it unexplained.
            'prefilled' => $this->prefilled_at !== null,

            'pending_email_approval' => $this->pending_email !== null,
            'pending_email' => $this->pending_email,
            'pending_email_requested_at' => $this->pending_email_requested_at,
            'pending_email_approved_at' => $this->pending_email_approved_at,

            // Step completion flags, which is all the wizard needs to decide
            // where to drop the carrier back in.
            'email_verified' => $this->email_verified_at !== null,
            'mobile_verified' => $this->mobile_verified_at !== null,
            'identity_verified' => $this->didit_status === 'Approved',
            'bank_verified' => $this->stripe_verified_at !== null,

            /*
            | The carrier chose to move past these without completing them, or —
            | for the bank — said they use a factoring company, which makes a
            | payout account moot.
            |
            | Kept separate from the *_verified flags on purpose. Folding a skip
            | into "verified" would tell the broker something was checked when
            | it was not, and that is the one thing this screen must not do.
            */
            'identity_skipped' => $this->identity_skipped_at !== null,
            'bank_skipped' => $this->bank_skipped_at !== null,

            // What the wizard gates on: done, or deliberately passed over.
            'identity_settled' => $this->didit_status === 'Approved'
                || $this->identity_skipped_at !== null,
            'bank_settled' => $this->stripe_verified_at !== null
                || $this->bank_skipped_at !== null,
            'factoring_answered' => $this->factoring_answered_at !== null,
            'questionnaire_completed' => $this->questionnaire_completed_at !== null,
            'documents_completed' => $this->documents_completed_at !== null,
            'signed' => $this->signed_at !== null,
            'completed' => $this->status === CarrierConnectRequest::STATUS_COMPLETED,

            // Present while a decision is pending so the wizard can explain the
            // hold-up instead of just failing the step.
            'identity_status' => $this->didit_status,

            // Flagged when Didit saw the session come from a VPN, Tor exit or a
            // data centre. Surfaced so the broker can review before tendering.
            'identity_risk_flagged' => (bool) $this->didit_risk_flagged,

            /*
            | Where this carrier came from.
            |
            | `onboarding_ip` is the address the invitation was opened from, and
            | exists for every request. `identity_ip` is what Didit saw during
            | the ID check, so it is only present when that step was actually
            | taken — the two disagreeing is worth a broker's attention.
            */
            'onboarding_ip' => $this->onboarding_ip,
            'identity_ip' => $this->didit_registration_ip,

            'factoring' => [
                'uses_factoring_company' => $this->uses_factoring_company,
                'company_name' => $this->factoring_company_name,
                'document_name' => $this->factoring_document_name,

                // Broker-side download; null for the carrier, who has no
                // session and does not need to re-read their own upload.
                'download_url' => $this->factoring_document_path
                    ? url('/api/v1/carrier-connect/'.$this->uuid.'/files/factoring')
                    : null,
            ],

            /*
            | Everything the carrier handed over, for the broker's records. The
            | required set is declared by CarrierConnectDocument::TYPES, and the
            | missing entries are listed explicitly so the wizard can render an
            | empty slot per type rather than inferring it.
            */
            'documents' => $this->whenLoaded('documents', fn () => $this->documents
                ->map(fn (CarrierConnectDocument $document) => [
                    'type' => $document->type,
                    'label' => $document->label(),
                    'name' => $document->name,
                    'size' => $document->size,
                    'mime' => $document->mime,
                    'uploaded_at' => $document->created_at,
                    'download_url' => url(
                        '/api/v1/carrier-connect/'.$this->uuid.'/files/'.$document->type
                    ),
                ])
                ->values()
            ),

            'documents_required' => collect(CarrierConnectDocument::TYPES)
                ->map(fn ($label, $type) => ['type' => $type, 'label' => $label])
                ->values(),

            // The signature image itself, once the agreement has been signed.
            'signature_url' => $this->signature_path
                ? url('/api/v1/carrier-connect/'.$this->uuid.'/files/signature')
                : null,

            'signed_at' => $this->signed_at,

            // Whether the carrier can log in to the carrier portal yet, and why
            // not if the provisioning call failed.
            'portal_account' => [
                'provisioned' => $this->portal_account_provisioned_at !== null,
                'email' => $this->portal_account_email,
                'provisioned_at' => $this->portal_account_provisioned_at,
                'error' => $this->portal_account_error,

                // Shown on the completion screen so the carrier can reach the
                // portal without digging the link out of the credentials email.
                // Same source as that email, so the two cannot diverge.
                'login_url' => CarrierAccountService::loginUrl(),
            ],

            // Served by this API rather than linked straight to S3: the bucket
            // sends no CORS headers, and the PDF viewer reads the file with XHR,
            // so a direct link renders nothing.
            //
            // url() resolves against the incoming request's host, not APP_URL,
            // so this stays correct however the API is reached — which matters
            // because APP_URL is not necessarily the address the carrier's
            // browser used.
            'agreement_url' => $this->whenLoaded(
                'agreementDocument',
                fn () => $this->agreementDocument
                    ? url('/api/v1/carrier-connect/agreement/'.$this->token)
                    : null
            ),

            'agreement' => $this->whenLoaded(
                'agreementDocument',
                fn () => $this->agreementDocument ? [
                    'name' => $this->agreementDocument->title
                        ?: $this->agreementDocument->file_name,
                    'url' => url('/api/v1/carrier-connect/agreement/'.$this->token),
                ] : null
            ),

            'sent_on' => $this->sent_on,
            'expires_at' => $this->sent_on?->copy()->addHours(
                (int) config('carrier_connect.request_lifetime_hours', 72)
            ),

            'created_at' => $this->created_at,
        ];
    }

    /**
     * The broker-facing state of the onboarding.
     *
     * Kept here rather than on the model so the display vocabulary lives with
     * the presentation, and so a single place decides what "rejected" means.
     *
     * @return array{0: string, 1: string}
     */
    private function stage(): array
    {
        if ($this->status === CarrierConnectRequest::STATUS_COMPLETED) {
            return ['completed', 'Onboarded'];
        }

        // Didit's terminal negatives. "In Review" is deliberately not here — it
        // is still pending, not a rejection.
        if (in_array($this->didit_status, ['Declined', 'Rejected', 'Failed', 'Expired'], true)) {
            return ['declined', 'ID check failed'];
        }

        /*
        | Waiting on the carrier to approve a different address. Only reported
        | as the stage when onboarding has not actually started — if the carrier
        | is already part-way through, that progress is the more useful headline
        | and the pending redirect is carried by `pending_email_approval`.
        */
        if ($this->pending_email !== null && $this->first_visit_at === null) {
            return ['pending_approval', 'Awaiting email approval'];
        }

        $lifetime = (int) config('carrier_connect.request_lifetime_hours', 72);

        if ($this->sent_on && $this->sent_on->copy()->addHours($lifetime)->isPast()) {
            return ['expired', 'Invitation expired'];
        }

        // Never opened the emailed link.
        if ($this->first_visit_at === null) {
            return ['invited', 'Invited'];
        }

        return ['in_progress', 'In progress'];
    }

    /**
     * Every step, in order, with enough state for the wizard to render it
     * without deciding for itself what the steps are.
     *
     * @return list<array{key: string, label: string, number: ?int, applicable: bool, skippable: bool, completed: bool, skipped: bool, settled: bool}>
     */
    private function steps(): array
    {
        $completed = [
            'phone' => $this->mobile_verified_at !== null,
            'identity' => $this->didit_status === 'Approved',
            'bank' => $this->stripe_verified_at !== null,
            'questionnaire' => $this->questionnaire_completed_at !== null,
            'documents' => $this->documents_completed_at !== null,
            'agreement' => $this->signed_at !== null,
        ];

        $skipped = [
            'identity' => $this->identity_skipped_at !== null,
            'bank' => $this->bank_skipped_at !== null,
        ];

        $number = 0;

        return collect(self::STEPS)
            ->map(function (string $label, string $key) use ($completed, $skipped, &$number) {
                $applicable = $this->stepApplies($key);
                $isSkipped = $skipped[$key] ?? false;

                return [
                    'key' => $key,
                    'label' => $label,

                    // Only applicable steps are numbered, so a factoring
                    // carrier is walked through 1..5 with no gap where the
                    // bank step would otherwise have been.
                    'number' => $applicable ? ++$number : null,

                    'applicable' => $applicable,
                    'skippable' => in_array($key, self::SKIPPABLE, true),
                    'completed' => $completed[$key],
                    'skipped' => $isSkipped,

                    // What the wizard gates on: done, or deliberately passed
                    // over. Kept apart from `completed` so the broker is never
                    // told something was checked when it was not.
                    'settled' => $completed[$key] || $isSkipped,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * A carrier paid by a factoring company is paid by them and not by the
     * broker, so there is no payout account to collect and the bank step does
     * not apply at all — which is a different thing from having skipped it.
     */
    private function stepApplies(string $key): bool
    {
        return ! ($key === 'bank' && (bool) $this->uses_factoring_company);
    }
}

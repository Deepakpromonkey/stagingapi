<?php

namespace App\Http\Controllers\Api\V1\Connect;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Connect\CarrierConnectTokenRequest;
use App\Http\Requests\Connect\CarrierDocumentRequest;
use App\Http\Requests\Connect\CarrierEsignRequest;
use App\Http\Requests\Connect\CarrierFactoringRequest;
use App\Http\Requests\Connect\CarrierQuestionnaireRequest;
use App\Http\Requests\Connect\SendCarrierConnectRequest;
use App\Http\Requests\Connect\VerifyCarrierOtpRequest;
use App\Http\Resources\CarrierConnectRequestResource;
use App\Mail\CarrierConnectInvitationMail;
use App\Models\BrokerAgreementDocument;
use App\Models\CarrierConnectAnswer;
use App\Models\CarrierConnectDocument;
use App\Models\CarrierConnectRequest;
use App\Models\CarrierQuestion;
use App\Models\Carriers\Carrier;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Carrier\CarrierAccountService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Carrier onboarding, end to end.
 *
 * The old implementation put all of this inside CarrierConnectRequestsModel and
 * keyed each request to the broker USER who sent it (`sender_id`), which meant a
 * colleague could not see or continue an onboarding a teammate had started. Here
 * the logic lives in the controller and every request is scoped to the broker
 * COMPANY, so the whole team shares one view of it.
 *
 * Two audiences:
 *   - broker side  (auth:sanctum, company scoped) — index, store
 *   - carrier side (public, invitation token)     — everything else
 */
class CarrierConnectController extends BaseController
{
    private const DIDIT_BASE_URL = 'https://verification.didit.me/v3';

    private const STRIPE_BASE_URL = 'https://api.stripe.com/v1';

    private const CLICKSEND_URL = 'https://rest.clicksend.com/v3/sms/send';

    public function __construct(
        private CarrierAccountService $carrierAccountService
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Broker side
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every onboarding the company has going, newest first.
     *
     * `stage` folds expiry and a failed ID check into the raw status column, so
     * it cannot be filtered in SQL directly — the filter is applied after the
     * resource has derived it, and the summary is counted the same way so the
     * two can never disagree.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search'));

        $query = CarrierConnectRequest::forCompany($request->user()->company_id)
            ->with(['user:id,first_name,last_name','documents',])
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('carrier_legal_name', 'like', "%{$search}%")
                        ->orWhere('carrier_dot_number', 'like', "%{$search}%")
                        ->orWhere('carrier_email', 'like', "%{$search}%");
                });
            })
            ->latest();

        $rows = $query->get()->map(function (CarrierConnectRequest $connectRequest) {
            $payload = (new CarrierConnectRequestResource($connectRequest))->resolve();

            $payload['invited_by'] = $connectRequest->user
                ? trim($connectRequest->user->first_name.' '.$connectRequest->user->last_name)
                : null;

            return $payload;
        });

        $summary = [
            'all' => $rows->count(),
            'invited' => $rows->where('stage', 'invited')->count(),
            'in_progress' => $rows->where('stage', 'in_progress')->count(),
            'completed' => $rows->where('stage', 'completed')->count(),
            'declined' => $rows->where('stage', 'declined')->count(),
            'expired' => $rows->where('stage', 'expired')->count(),
        ];

        $stage = $request->input('stage');

        if ($stage && $stage !== 'all') {
            $rows = $rows->where('stage', $stage)->values();
        }

        return $this->success([
            'requests' => $rows->values(),
            'summary' => $summary,
        ], 'Connect requests retrieved.');
    }

    /**
     * The Connect button on a carrier profile. Creates (or refreshes) the
     * request and mails the carrier their onboarding link.
     */
    public function store(SendCarrierConnectRequest $request)
    {
        $user = $request->user();

        $carrier = $this->findCarrier($request->validated()['row_id']);

        if (! $carrier) {
            return $this->error('Carrier not found in the carrier directory.', null, 404);
        }

        $email = trim((string) (config('carrier_connect.test_email') ?: $carrier->email_address));

        // The FMCSA feed is dirty — plenty of rows carry junk in this column —
        // so a bad address has to fail loudly here rather than as a swallowed
        // mail exception that leaves the broker thinking the invite went out.
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'This carrier has no usable email address on file, so an invitation cannot be sent.',
                null,
                422
            );
        }

        // The document the carrier will be asked to sign at the last step. Null
        // is fine — the carrier can still onboard, they just cannot e-sign yet.
        $agreement = BrokerAgreementDocument::forCompany($user->company_id)
            ->active()
            ->latest()
            ->first();

        $connectRequest = DB::transaction(function () use ($user, $carrier, $email, $agreement) {

            // updateOrCreate on (company_id, carrier_row_id) means re-clicking
            // Connect resends the invitation and pushes the expiry out, rather
            // than colliding on the unique key.
            $connectRequest = CarrierConnectRequest::firstOrNew([
                'company_id' => $user->company_id,
                'carrier_row_id' => $carrier->row_id,
            ]);

            if (! $connectRequest->exists) {
                $connectRequest->uuid = Str::uuid();
                $connectRequest->token = Str::random(64);
                $connectRequest->status = CarrierConnectRequest::STATUS_NEW;
            }

            $connectRequest->fill([
                'user_id' => $user->id,
                'carrier_dot_number' => $carrier->dot_number,
                'carrier_legal_name' => $carrier->legal_name ?: $carrier->dba_name,
                'carrier_email' => $email,
                'carrier_phone' => $carrier->telephone,
                'agreement_document_id' => $agreement?->id,
                'sent_on' => now(),
            ])->save();

            return $connectRequest->fresh(['company']);
        });

        $this->sendInvitationMail($connectRequest, $user);

        return $this->respondWithRequest(
            $connectRequest,
            'Connection request sent. The carrier has been emailed an onboarding link.',
            201
        );
    }


    public function downloadFile(Request $request, string $uuid, string $type)
    {
        $connectRequest = CarrierConnectRequest::forCompany($request->user()->company_id)
            ->where('uuid', $uuid)
            ->first();

        if (! $connectRequest) {
            return $this->error('Onboarding request not found.', null, 404);
        }

        [$disk, $path, $name] = $this->resolveFile($connectRequest, $type);

        if (! $disk || ! $path || ! Storage::disk($disk)->exists($path)) {
            return $this->error('That document has not been uploaded.', null, 404);
        }

        return Storage::disk($disk)->download($path, $name);
    }

    /**
     * Maps a request-scoped file type onto its stored location.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} disk, path, download name
     */
    private function resolveFile(CarrierConnectRequest $connectRequest, string $type): array
    {
        if ($type === 'factoring') {
            return [
                $connectRequest->factoring_document_disk,
                $connectRequest->factoring_document_path,
                $connectRequest->factoring_document_name ?: 'notice-of-assignment.pdf',
            ];
        }

        if ($type === 'signature') {
            return [
                $connectRequest->signature_disk,
                $connectRequest->signature_path,
                'signature.png',
            ];
        }

        $document = $connectRequest->documents()->where('type', $type)->first();

        if (! $document) {
            return [null, null, null];
        }

        return [$document->disk, $document->path, $document->name];
    }



    // ─────────────────────────────────────────────────────────────────────────
    // Carrier side — the invitation link
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Landing endpoint for the link in the invitation email. Reaching it is
     * proof the carrier controls the mailbox we sent it to, so this is what
     * marks the email verified.
     */
    public function open(string $token)
    {
        $connectRequest = CarrierConnectRequest::where('token', $token)->first();

        if (! $connectRequest) {
            return redirect()->away($this->frontendUrl('/carrier/invalid-access'));
        }

        if ($this->isExpired($connectRequest)) {
            return redirect()->away($this->frontendUrl('/carrier/invalid-access?reason=expired'));
        }

        if ($connectRequest->first_visit_at === null) {
            $connectRequest->forceFill([
                'first_visit_at' => now(),
                'email_verified_at' => now(),
                'status' => CarrierConnectRequest::STATUS_EMAIL_VERIFIED,
            ])->save();
        }

        return redirect()->away($this->frontendUrl('/carrier/connect/'.$token));
    }

    /**
     * Everything the onboarding wizard needs to render itself.
     */
    public function load(CarrierConnectTokenRequest $request)
    {
        $connectRequest = $this->resolveRequest($request->validated()['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        $carrier = $this->findCarrier($connectRequest->carrier_row_id);

        $connectRequest->load('agreementDocument');

        return $this->success([
            'connect_request' => new CarrierConnectRequestResource($connectRequest),
            'carrier' => $carrier ? [
                'row_id' => $carrier->row_id,
                'dot_number' => $carrier->dot_number,
                'mc_number' => $carrier->mc_number,
                'legal_name' => $carrier->legal_name,
                'dba_name' => $carrier->dba_name,
                'email_address' => $carrier->email_address,
                'telephone' => $carrier->telephone,
                'phy_city' => $carrier->phy_city,
                'phy_state' => $carrier->phy_state,
            ] : null,
            'broker' => [
                'company_name' => $connectRequest->company->company_name,
            ],
        ], 'Onboarding loaded.');
    }

    /**
     * Stream the broker's agreement to the carrier.
     *
     * Deliberately proxied rather than handing the browser a signed S3 link:
     * the bucket sends no CORS headers, and the PDF viewer reads the file with
     * XHR, so a direct link renders nothing. Going through the API also keeps
     * the signed URL — and the bucket layout — out of the browser entirely.
     */
    public function agreement(string $token)
    {
        $connectRequest = $this->resolveRequest($token);

        if (! $connectRequest) {
            abort(404);
        }

        $document = $connectRequest->agreementDocument;

        if (! $document) {
            abort(404);
        }

        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->file_path)) {
            Log::error('Agreement missing from storage', [
                'connect_request' => $connectRequest->uuid,
                'path' => $document->file_path,
            ]);

            abort(404);
        }

        return response()->stream(
            function () use ($disk, $document) {
                $stream = $disk->readStream($document->file_path);

                if ($stream) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $document->mime_type ?: 'application/pdf',

                // inline, so the viewer renders it instead of downloading it.
                'Content-Disposition' => 'inline; filename="'.addslashes($document->file_name).'"',

                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 1 — phone
    // ─────────────────────────────────────────────────────────────────────────

    public function sendOtp(CarrierConnectTokenRequest $request)
    {
        $connectRequest = $this->resolveRequest($request->validated()['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        $phone = $this->normalisePhone(
            config('carrier_connect.test_phone') ?: $connectRequest->carrier_phone
        );

        if (! $phone) {
            return $this->error(
                'No phone number is on file for this carrier. Please contact the broker.',
                null,
                422
            );
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $connectRequest->forceFill([
            'otp' => $otp,
            'otp_sent_at' => now(),

            // A fresh code starts a fresh set of attempts.
            'otp_attempts' => 0,
            'last_otp_attempt_at' => null,
        ])->save();

        // The code is only ever logged in local, so a failed or unconfigured
        // gateway does not block development. Never in any other environment —
        // this is a live credential.
        if (app()->environment('local')) {
            Log::info('=============== Carrier Connect OTP ===============');
            Log::info('To', ['phone' => $phone]);
            Log::info('Code', ['otp' => $otp]);
            Log::info('==================================================');
        }

        $sent = $this->sendSms(
            $phone,
            "Your DollarTraq verification code is {$otp}. It expires in "
                .config('carrier_connect.otp_lifetime_minutes').' minutes. Do not share it with anyone.'
        );

        if (! $sent) {
            return $this->error('We could not send the code. Please try again in a moment.', null, 502);
        }

        return $this->success([
            'phone_hint' => $this->maskPhone($phone),
        ], 'Verification code sent.');
    }

    public function verifyOtp(VerifyCarrierOtpRequest $request)
    {
        $data = $request->validated();

        $connectRequest = $this->resolveRequest($data['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        if ($connectRequest->otp === null || $connectRequest->otp_sent_at === null) {
            return $this->error('Request a verification code first.', null, 422);
        }

        $lifetime = (int) config('carrier_connect.otp_lifetime_minutes', 15);

        if ($connectRequest->otp_sent_at->lte(now()->subMinutes($lifetime))) {
            $connectRequest->forceFill(['otp' => null])->save();

            return $this->error('That code has expired. Please request a new one.', null, 422);
        }

        $maxAttempts = (int) config('carrier_connect.otp_max_attempts', 3);

        // The lockout is tied to the code's own lifetime: a carrier who burns
        // their attempts must wait for the code to lapse, then request a new
        // one. The old check compared against the wrong side of the window and
        // so never actually locked anyone out.
        if ($connectRequest->otp_attempts >= $maxAttempts) {
            return $this->error(
                'Too many incorrect attempts. Please request a new code.',
                null,
                429
            );
        }

        if (! hash_equals($connectRequest->otp, (string) $data['otp'])) {
            $connectRequest->forceFill([
                'otp_attempts' => $connectRequest->otp_attempts + 1,
                'last_otp_attempt_at' => now(),
            ])->save();

            $remaining = max(0, $maxAttempts - $connectRequest->otp_attempts);

            return $this->error(
                $remaining > 0
                    ? "That code is not correct. {$remaining} attempt(s) left."
                    : 'That code is not correct. Please request a new code.',
                null,
                422
            );
        }

        $connectRequest->forceFill([
            'otp' => null,
            'otp_attempts' => 0,
            'last_otp_attempt_at' => now(),
            'mobile_verified_at' => now(),
            'status' => CarrierConnectRequest::STATUS_MOBILE_VERIFIED,
        ])->save();

        return $this->respondWithRequest($connectRequest, 'Phone number verified.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 2 — government ID (Didit)
    // ─────────────────────────────────────────────────────────────────────────

    public function startIdentityVerification(CarrierConnectTokenRequest $request)
    {
        $connectRequest = $this->resolveRequest($request->validated()['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        $apiKey = config('services.didit.api_key');
        $workflowId = config('services.didit.workflow_id');

        if (! $apiKey || ! $workflowId) {
            Log::error('Didit is not configured; cannot start identity verification.');

            return $this->error('Identity verification is unavailable right now.', null, 503);
        }

        $response = Http::withHeaders(['x-api-key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->post(self::DIDIT_BASE_URL.'/session/', [
                'workflow_id' => $workflowId,

                // Comes back to us on the webhook, so it must identify the
                // request without being guessable.
                'vendor_data' => $connectRequest->token,

                'callback' => $this->frontendUrl('/carrier/connect/'.$connectRequest->token),
            ]);

        if ($response->failed()) {
            Log::error('Didit session create failed', [
                'connect_request' => $connectRequest->uuid,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $this->error('Could not start identity verification. Please try again.', null, 502);
        }

        $payload = $response->json();

        if (empty($payload['url'])) {
            return $this->error('Could not start identity verification. Please try again.', null, 502);
        }

        $connectRequest->forceFill([
            'didit_session_id' => $payload['session_id'] ?? null,
        ])->save();

        return $this->success(['url' => $payload['url']], 'Identity verification started.');
    }

    /**
     * Called when Didit hands the carrier back to us. The webhook is the
     * authoritative signal, but carriers often land here first.
     */
    public function checkIdentityVerification(CarrierConnectTokenRequest $request)
    {
        $connectRequest = $this->resolveRequest($request->validated()['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        if (! $connectRequest->didit_session_id) {
            return $this->error('Start identity verification first.', null, 422);
        }

        $response = Http::withHeaders(['x-api-key' => config('services.didit.api_key')])
            ->acceptJson()
            ->get(self::DIDIT_BASE_URL."/session/{$connectRequest->didit_session_id}/decision/");

        if ($response->failed()) {
            Log::error('Didit decision fetch failed', [
                'connect_request' => $connectRequest->uuid,
                'status' => $response->status(),
            ]);

            return $this->error('Could not read the verification result. Please try again.', null, 502);
        }

        $payload = $response->json();

        $status = $payload['status'] ?? 'Unknown';

        $this->applyIdentityDecision($connectRequest, $status, $payload);

        if ($status !== 'Approved') {
            return $this->error(
                'Your documents are under review. We will email you once the check completes.',
                ['identity_status' => $status],
                202
            );
        }

        return $this->respondWithRequest($connectRequest->refresh(), 'Identity verified.');
    }

    /**
     * Didit server-to-server callback. `vendor_data` is the invitation token we
     * sent when opening the session.
     */
    public function identityWebhook(Request $request)
    {
        $token = (string) $request->input('vendor_data');

        $connectRequest = CarrierConnectRequest::where('token', $token)->first();

        if (! $connectRequest) {
            Log::error('Didit webhook for unknown connect request', [
                'vendor_data' => $token,
            ]);

            return response()->json(['error' => 'Request not found'], 404);
        }

        $this->applyIdentityDecision(
            $connectRequest,
            (string) $request->input('status'),
            $request->all()
        );

        return response()->json(['status' => 'success']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 3 — payouts (Stripe Express)
    // ─────────────────────────────────────────────────────────────────────────

    public function connectStripe(CarrierConnectTokenRequest $request)
    {
        $connectRequest = $this->resolveRequest($request->validated()['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        if (! config('services.stripe.secret')) {
            Log::error('Stripe is not configured; cannot connect a payout account.');

            return $this->error('Bank verification is unavailable right now.', null, 503);
        }

        $accountId = $connectRequest->stripe_express_account;

        // Reuse the account across retries, otherwise every abandoned attempt
        // leaves an orphaned Express account behind on the Stripe side.
        if (! $accountId) {
            $account = $this->stripe()->post(self::STRIPE_BASE_URL.'/accounts', [
                'type' => 'express',
                'country' => 'US',
                'email' => $connectRequest->carrier_email,
                'business_type' => 'company',
                'company[name]' => $connectRequest->carrier_legal_name,
                'capabilities[card_payments][requested]' => 'true',
                'capabilities[transfers][requested]' => 'true',
            ]);

            if ($account->failed()) {
                Log::error('Stripe Express account create failed', [
                    'connect_request' => $connectRequest->uuid,
                    'body' => $account->body(),
                ]);

                return $this->error('Could not set up the payout account. Please try again.', null, 502);
            }

            $accountId = $account->json('id');

            $connectRequest->forceFill(['stripe_express_account' => $accountId])->save();
        }

        $returnUrl = $this->frontendUrl('/carrier/connect/'.$connectRequest->token);

        $link = $this->stripe()->post(self::STRIPE_BASE_URL.'/account_links', [
            'account' => $accountId,
            'refresh_url' => $returnUrl.'?stripeConnect=expired',
            'return_url' => $returnUrl.'?stripeConnect=processing',
            'type' => 'account_onboarding',
        ]);

        if ($link->failed()) {
            Log::error('Stripe onboarding link failed', [
                'connect_request' => $connectRequest->uuid,
                'body' => $link->body(),
            ]);

            return $this->error('Could not open the bank connection form. Please try again.', null, 502);
        }

        return $this->success(['url' => $link->json('url')], 'Bank connection started.');
    }

    public function verifyStripe(CarrierConnectTokenRequest $request)
    {
        $connectRequest = $this->resolveRequest($request->validated()['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        if (! $connectRequest->stripe_express_account) {
            return $this->error('Connect a bank account first.', null, 422);
        }

        $account = $this->stripe()
            ->get(self::STRIPE_BASE_URL.'/accounts/'.$connectRequest->stripe_express_account);

        if ($account->failed()) {
            Log::error('Stripe account retrieve failed', [
                'connect_request' => $connectRequest->uuid,
                'body' => $account->body(),
            ]);

            return $this->error('Could not check the bank connection. Please try again.', null, 502);
        }

        if (! $account->json('details_submitted')) {
            return $this->error(
                'Your bank details are incomplete. Please finish the Stripe form.',
                ['requirements' => $account->json('requirements.currently_due', [])],
                422
            );
        }

        $connectRequest->forceFill([
            'stripe_verified_at' => now(),
            'status' => CarrierConnectRequest::STATUS_BANK_VERIFIED,
        ])->save();

        return $this->respondWithRequest($connectRequest, 'Bank account connected.');
    }

    /**
     * Factoring, asked alongside bank verification.
     *
     * If the carrier factors its receivables the broker cannot pay the carrier
     * directly, so the notice of assignment is mandatory on a yes.
     */
    public function saveFactoring(CarrierFactoringRequest $request)
    {
        $data = $request->validated();

        $connectRequest = $this->resolveRequest($data['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        $usesFactoring = $request->boolean('uses_factoring_company');

        $attributes = [
            'uses_factoring_company' => $usesFactoring,
            'factoring_company_name' => $usesFactoring ? ($data['factoring_company_name'] ?? null) : null,
            'factoring_answered_at' => now(),
        ];

        if ($usesFactoring) {
            $file = $request->file('document');

            $stored = $this->storePrivateFile(
                $file,
                'carrier-factoring/'.$connectRequest->company_id,
                $connectRequest->uuid
            );

            if (! $stored) {
                return $this->error('Could not save the factoring document. Please try again.', null, 500);
            }

            $attributes['factoring_document_disk'] = $stored['disk'];
            $attributes['factoring_document_path'] = $stored['path'];
            $attributes['factoring_document_name'] = $file->getClientOriginalName();
        } else {
            // Switching back to no clears a document uploaded on an earlier pass,
            // otherwise the broker would still see a notice of assignment for a
            // carrier who has since said they do not factor.
            $this->deletePrivateFile(
                $connectRequest->factoring_document_disk,
                $connectRequest->factoring_document_path
            );

            $attributes['factoring_document_disk'] = null;
            $attributes['factoring_document_path'] = null;
            $attributes['factoring_document_name'] = null;
        }

        $connectRequest->forceFill($attributes)->save();

        return $this->respondWithRequest($connectRequest, 'Factoring details saved.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 4 — the broker's own questions
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The questions this broker company configured in its dashboard, plus
     * anything the carrier has already answered.
     */
    public function questions(CarrierConnectTokenRequest $request)
    {
        $connectRequest = $this->resolveRequest($request->validated()['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        $questions = CarrierQuestion::where('company_id', $connectRequest->company_id)
            ->orderBy('id')
            ->get();

        $answers = $connectRequest->answers()->get()->keyBy('carrier_question_id');

        return $this->success([
            'questions' => $questions->map(function (CarrierQuestion $question) use ($answers) {
                $answer = $answers->get($question->id);

                return [
                    'id' => $question->id,
                    'question' => $question->question,
                    'answer_type' => $question->answer_type,
                    'is_required' => (bool) $question->is_required,
                    'answer' => $answer?->answer,
                    'answer_document_name' => $answer?->answer_document_name,
                ];
            })->values(),

            'completed' => $connectRequest->questionnaire_completed_at !== null,
        ], 'Questions retrieved.');
    }

    /**
     * Store the carrier's answers.
     *
     * Required-ness and answer shape come from the broker's question set, so the
     * rules are applied here rather than in the form request.
     */
    public function saveAnswers(CarrierQuestionnaireRequest $request)
    {
        $data = $request->validated();

        $connectRequest = $this->resolveRequest($data['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        $questions = CarrierQuestion::where('company_id', $connectRequest->company_id)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        // Nothing configured by this broker — the step is a no-op rather than a
        // dead end the carrier cannot get past.
        if ($questions->isEmpty()) {
            $connectRequest->forceFill([
                'questionnaire_completed_at' => now(),
                'status' => CarrierConnectRequest::STATUS_QUESTIONNAIRE_DONE,
            ])->save();

            return $this->respondWithRequest($connectRequest, 'No questions to answer.');
        }

        $submitted = collect($data['answers'])->keyBy('question_id');

        // A file uploaded on an earlier pass still counts as answered, so
        // re-submitting the step does not force the carrier to attach it again.
        $existing = $connectRequest->answers()->get()->keyBy('carrier_question_id');

        $errors = [];

        foreach ($questions as $question) {
            $entry = $submitted->get($question->id);

            $isUpload = $question->answer_type === 'Image Upload';

            $hasFile = $isUpload && (
                $request->hasFile("answers.{$question->id}.document")
                || filled($existing->get($question->id)?->answer_document_path)
            );

            $hasText = filled($entry['answer'] ?? null);

            if ($question->is_required && ! ($isUpload ? $hasFile : $hasText)) {
                $errors["answers.{$question->id}"] = [
                    $isUpload
                        ? 'Please upload a file for: '.$question->question
                        : 'Please answer: '.$question->question,
                ];

                continue;
            }

            if (! $isUpload && $hasText && $question->answer_type === 'Number' && ! is_numeric($entry['answer'])) {
                $errors["answers.{$question->id}"] = [
                    'This answer must be a number: '.$question->question,
                ];
            }
        }

        if ($errors) {
            return $this->error('Some answers are missing or invalid.', $errors, 422);
        }

        DB::transaction(function () use ($questions, $submitted, $request, $connectRequest) {

            foreach ($questions as $question) {
                $entry = $submitted->get($question->id);

                $attributes = [
                    'question_text' => $question->question,
                    'answer_type' => $question->answer_type,
                    'answer' => $entry['answer'] ?? null,
                ];

                $file = $request->file("answers.{$question->id}.document");

                if ($file) {
                    $stored = $this->storePrivateFile(
                        $file,
                        'carrier-answers/'.$connectRequest->company_id,
                        $connectRequest->uuid.'-q'.$question->id
                    );

                    if ($stored) {
                        $attributes['answer_document_disk'] = $stored['disk'];
                        $attributes['answer_document_path'] = $stored['path'];
                        $attributes['answer_document_name'] = $file->getClientOriginalName();
                    }
                }

                CarrierConnectAnswer::updateOrCreate(
                    [
                        'carrier_connect_request_id' => $connectRequest->id,
                        'carrier_question_id' => $question->id,
                    ],
                    $attributes
                );
            }

            $connectRequest->forceFill([
                'questionnaire_completed_at' => now(),
                'status' => CarrierConnectRequest::STATUS_QUESTIONNAIRE_DONE,
            ])->save();
        });

        return $this->respondWithRequest($connectRequest, 'Answers saved.');
    }


 public function uploadDocument(CarrierDocumentRequest $request)
    {
        $data = $request->validated();

        $connectRequest = $this->resolveRequest($data['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        $file = $request->file('document');

        $stored = $this->storePrivateFile(
            $file,
            'carrier-documents/'.$connectRequest->company_id,
            $connectRequest->uuid.'-'.$data['type']
        );

        if (! $stored) {
            return $this->error('Could not save the document. Please try again.', null, 500);
        }

        $existing = $connectRequest->documents()
            ->where('type', $data['type'])
            ->first();

        $connectRequest->documents()->updateOrCreate(
            ['type' => $data['type']],
            [
                'disk' => $stored['disk'],
                'path' => $stored['path'],
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
            ]
        );

        // Only once the row points at the new file, so a failed write cannot
        // leave the request with no document at all.
        if ($existing) {
            $this->deletePrivateFile($existing->disk, $existing->path);
        }

        $this->syncDocumentsCompletion($connectRequest);

        return $this->respondWithRequest($connectRequest->refresh(), 'Document uploaded.');
    }

    /**
     * Remove one document, so a carrier can correct a wrong upload.
     */
    public function deleteDocument(CarrierConnectTokenRequest $request, string $type)
    {
        $data = $request->validated();

        $connectRequest = $this->resolveRequest($data['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        $document = $connectRequest->documents()->where('type', $type)->first();

        if ($document) {
            $this->deletePrivateFile($document->disk, $document->path);
            $document->delete();
        }

        $this->syncDocumentsCompletion($connectRequest);

        return $this->respondWithRequest($connectRequest->refresh(), 'Document removed.');
    }

    /**
     * The step is done exactly when every required type is present, and undone
     * again if one is removed — so the flag can never outlive the files.
     */
    private function syncDocumentsCompletion(CarrierConnectRequest $connectRequest): void
    {
        $present = $connectRequest->documents()->pluck('type')->all();

        $missing = array_diff(array_keys(CarrierConnectDocument::TYPES), $present);

        $connectRequest->forceFill([
            'documents_completed_at' => $missing ? null : ($connectRequest->documents_completed_at ?? now()),
        ])->save();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 5 — e-sign
    // ─────────────────────────────────────────────────────────────────────────

    public function esign(CarrierEsignRequest $request)
    {
        $data = $request->validated();

        $connectRequest = $this->resolveRequest($data['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        // There is nothing to sign until the broker has uploaded an agreement,
        // so a signature at this point would not be attached to any document.
        if (! $connectRequest->agreement_document_id) {
            return $this->error(
                'The broker has not uploaded an agreement yet. Please try again later.',
                null,
                422
            );
        }

        $stored = $this->storePrivateFile(
            $request->file('signature'),
            'carrier-signatures/'.$connectRequest->company_id,
            $connectRequest->uuid
        );

        if (! $stored) {
            return $this->error('Could not save your signature. Please try again.', null, 500);
        }

        $connectRequest->forceFill([
            'signature_disk' => $stored['disk'],
            'signature_path' => $stored['path'],
            'signature_page' => (int) $data['page'],
            'signature_x_pct' => $data['x_pct'],
            'signature_y_pct' => $data['y_pct'],
            'signed_at' => now(),
            'status' => CarrierConnectRequest::STATUS_COMPLETED,
        ])->save();

        // Signing is the last step, so this is where the carrier stops being a
        // one-off invitation and gets an account of their own. Provisioning
        // must not be able to undo a signature that is already saved, so a
        // failure here is logged and the onboarding still reports complete —
        // the account can be re-provisioned from the stored request.
        $accountCreated = false;

        try {
            $accountCreated = $this->carrierAccountService->provisionFor($connectRequest) !== null;
        } catch (\Throwable $e) {
            Log::error('Carrier portal account provisioning failed', [
                'connect_request' => $connectRequest->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->respondWithRequest(
            $connectRequest->refresh(),
            $accountCreated
                ? 'Agreement signed. Onboarding complete — your carrier portal login has been emailed to you.'
                : 'Agreement signed. Onboarding complete.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Single exit point for every response that carries a connect request.
     *
     * The agreement relation is loaded here rather than at each call site
     * because CarrierConnectRequestResource exposes `agreement_url` via
     * whenLoaded — without this, the key would silently disappear from the
     * step-completion responses and the wizard would lose the document it is
     * asking the carrier to sign.
     */
    private function respondWithRequest(
        CarrierConnectRequest $connectRequest,
        string $message,
        int $code = 200
    ) {
        return $this->success(
            new CarrierConnectRequestResource($connectRequest->load('agreementDocument')),
            $message,
            $code
        );
    }

    /**
     * Carriers live in the external (EC2) database. The MC number comes from
     * the FMCSA authority record, matched on DOT number.
     */
    private function findCarrier(string $rowId): ?Carrier
    {
        $carrier = Carrier::query()
            ->with('authority:dot_number,docket_number')
            ->where('row_id', $rowId)
            ->first();

        if ($carrier) {
            $carrier->mc_number = $carrier->authority?->docket_number;
        }

        return $carrier;
    }

    /**
     * A token only resolves while the invitation is still inside its window;
     * expired links behave exactly like unknown ones.
     */
    private function resolveRequest(string $token): ?CarrierConnectRequest
    {
        $connectRequest = CarrierConnectRequest::with('company')
            ->where('token', $token)
            ->first();

        if (! $connectRequest || $this->isExpired($connectRequest)) {
            return null;
        }

        return $connectRequest;
    }

    private function isExpired(CarrierConnectRequest $connectRequest): bool
    {
        // A completed onboarding stays readable so the carrier can see the
        // finished state instead of hitting an error page.
        if ($connectRequest->status === CarrierConnectRequest::STATUS_COMPLETED) {
            return false;
        }

        $lifetime = (int) config('carrier_connect.request_lifetime_hours', 72);

        return $connectRequest->sent_on === null
            || $connectRequest->sent_on->copy()->addHours($lifetime)->isPast();
    }

    /**
     * Uses the company's own carrier_connect email template when it has one,
     * and falls back to the packaged mailable otherwise. A delivery failure
     * must not lose the request that was just created, so it only logs.
     */
    private function sendInvitationMail(CarrierConnectRequest $connectRequest, User $user): void
    {
        $connectUrl = rtrim(config('app.url'), '/')
            .'/carrier/connect/'.$connectRequest->token;

        $brokerName = trim($user->first_name.' '.$user->last_name) ?: $connectRequest->company->company_name;

        try {
            $template = EmailTemplate::forCompany($connectRequest->company_id)
                ->active()
                ->where('type', 'carrier_connect')
                ->orderByDesc('is_default')
                ->first();

            if ($template) {
                $rendered = $template->render([
                    'carrier_name' => $connectRequest->carrier_legal_name,
                    'dot_number' => $connectRequest->carrier_dot_number,
                    'company_name' => $connectRequest->company->company_name,
                    'sender_name' => $brokerName,
                    'connect_url' => $connectUrl,
                    'expires_at' => $connectRequest->sent_on
                        ->copy()
                        ->addHours((int) config('carrier_connect.request_lifetime_hours', 72))
                        ->format('m/d/y h:i A'),
                ]);

                Mail::html($rendered['body_html'], function ($message) use ($connectRequest, $rendered) {
                    $message->to($connectRequest->carrier_email)
                        ->subject($rendered['subject']);
                });
            } else {
                Mail::to($connectRequest->carrier_email)
                    ->send(new CarrierConnectInvitationMail($connectRequest, $connectUrl, $brokerName));
            }
        } catch (\Throwable $e) {
            Log::error('Carrier connect invitation email failed', [
                'connect_request' => $connectRequest->uuid,
                'email' => $connectRequest->carrier_email,
                'error' => $e->getMessage(),
            ]);
        }

        if (app()->environment('local')) {
            Log::info('=========== Carrier Connect Invitation ===========');
            Log::info('To', ['email' => $connectRequest->carrier_email]);
            Log::info('Link', ['url' => $connectUrl]);
            Log::info('==================================================');
        }
    }

    private function applyIdentityDecision(
        CarrierConnectRequest $connectRequest,
        string $status,
        array $payload
    ): void {
        $ipAnalysis = $payload['ip_analysis'] ?? [];

        $usesProxy = (bool) ($ipAnalysis['is_vpn_or_tor'] ?? false);
        $isDataCentre = (bool) ($ipAnalysis['is_data_center'] ?? false);

        $attributes = [
            'didit_status' => $status,
            'didit_responded_at' => now(),

            // The complete decision. Previously only a hand-picked summary of
            // ip_analysis was kept, so any payload without that key — which is
            // most of them — saved nothing at all, and the document checks, face
            // match and extracted ID fields were all discarded.
            //
            // vendor_data is dropped: it is our own invitation token echoed back,
            // it is already a column on this row, and anyone holding it can
            // resume the onboarding. It does not belong in a blob that may later
            // be surfaced to the broker.
            'didit_response' => Arr::except($payload, ['vendor_data']),

            // Denormalised from the payload purely so flagged onboardings can be
            // filtered in a query without unpacking the JSON.
            'didit_risk_flagged' => $usesProxy || $isDataCentre,
            'didit_registration_ip' => $ipAnalysis['ip_address'] ?? null,
        ];

        // Only advance the status on approval; a rejected or pending decision
        // must not move the carrier past the ID step.
        if ($status === 'Approved') {
            $attributes['status'] = CarrierConnectRequest::STATUS_ID_VERIFIED;
        }

        $connectRequest->forceFill($attributes)->save();
    }

    /**
     * Carrier uploads — signatures, factoring notices, answer attachments — all
     * land on the default (private) disk, alongside the broker agreements they
     * relate to. Nothing here is ever publicly readable.
     *
     * @return array{disk: string, path: string}|null
     */
    private function storePrivateFile($file, string $directory, string $prefix): ?array
    {
        if (! $file) {
            return null;
        }

        $disk = config('filesystems.default');

        $name = $prefix.'-'.now()->format('YmdHis').'-'.Str::random(6)
            .'.'.$file->getClientOriginalExtension();

        $path = Storage::disk($disk)->putFileAs($directory, $file, $name);

        return $path ? ['disk' => $disk, 'path' => $path] : null;
    }

    private function deletePrivateFile(?string $disk, ?string $path): void
    {
        if (! $disk || ! $path) {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            // An orphaned file is not worth failing the carrier's request over.
            Log::warning('Could not delete carrier upload', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function stripe(): PendingRequest
    {
        return Http::withToken(config('services.stripe.secret'))
            ->asForm()
            ->acceptJson();
    }

    private function sendSms(string $to, string $body): bool
    {
        $username = config('services.clicksend.username');
        $key = config('services.clicksend.key');

        if (! $username || ! $key) {
            Log::error('ClickSend is not configured; cannot send onboarding OTP.');

            // In local the code has just been written to the log, so the step is
            // still testable. Anywhere else, reporting success for a message
            // that was never sent is worse than failing — it leaves the carrier
            // waiting for a code that will not arrive.
            return app()->environment('local');
        }

        try {
            $response = Http::withBasicAuth($username, $key)
                ->acceptJson()
                ->asJson()
                ->post(self::CLICKSEND_URL, [
                    'messages' => [[
                        'to' => $to,
                        'body' => $body,
                        'source' => 'php',
                    ]],
                ]);

            if ($response->failed() || $response->json('response_code') !== 'SUCCESS') {
                Log::error('ClickSend rejected the request', [
                    'to' => $this->maskPhone($to),
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            // A top-level SUCCESS only means ClickSend accepted the request. The
            // individual message can still fail — INSUFFICIENT_CREDIT is the
            // common one — and treating that as sent leaves the carrier waiting
            // for a code that never arrives.
            $messageStatus = $response->json('data.messages.0.status');

            if (! in_array($messageStatus, ['SUCCESS', 'QUEUED'], true)) {
                Log::error('ClickSend accepted the request but did not send', [
                    'to' => $this->maskPhone($to),
                    'message_status' => $messageStatus,
                    'balance_hint' => $response->json('data.total_price'),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('ClickSend SMS threw', [
                'to' => $this->maskPhone($to),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * FMCSA phone numbers arrive as bare digits, ClickSend wants E.164.
     */
    private function normalisePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        if (Str::startsWith($phone, '+')) {
            return '+'.preg_replace('/\D/', '', $phone);
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 10) {
            return config('carrier_connect.default_dial_code').$digits;
        }

        if (strlen($digits) === 11 && Str::startsWith($digits, '1')) {
            return '+'.$digits;
        }

        return $digits === '' ? null : '+'.$digits;
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) <= 4
            ? $phone
            : str_repeat('•', strlen($phone) - 4).substr($phone, -4);
    }

    private function frontendUrl(string $path): string
    {
        return rtrim(config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }
}

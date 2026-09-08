<?php

namespace App\Http\Controllers\Api\V1\Connect;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Connect\CarrierConnectTokenRequest;
use App\Http\Requests\Connect\CarrierDocumentRequest;
use App\Http\Requests\Connect\CarrierEldVerifyRequest;
use App\Http\Requests\Connect\CarrierEsignRequest;
use App\Http\Requests\Connect\CarrierFactoringRequest;
use App\Http\Requests\Connect\CarrierQuestionnaireRequest;
use App\Http\Requests\Connect\CarrierSkipStepRequest;
use App\Http\Requests\Connect\SendCarrierConnectRequest;
use App\Http\Requests\Connect\VerifyCarrierOtpRequest;
use App\Http\Resources\CarrierConnectRequestResource;
use App\Mail\CarrierAlternateEmailApprovalMail;
use App\Mail\CarrierConnectInvitationMail;
use App\Models\BrokerAgreementDocument;
use App\Models\CarrierConnectAnswer;
use App\Models\CarrierConnectDocument;
use App\Models\CarrierConnectRequest;
use App\Models\CarrierLoginAttempt;
use App\Models\CarrierQuestion;
use App\Models\Carriers\Carrier;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Carrier\CarrierAccountService;
use App\Services\Eld\EldConnectionService;
use App\Services\SmsSender;
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

    public function __construct(
        private CarrierAccountService $carrierAccountService,
        private EldConnectionService $eldConnections,
        private SmsSender $sms
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

        $requests = $query->get();

        /*
        | The most recent successful portal sign-in per carrier, so the broker
        | can compare where the carrier onboarded from with where they actually
        | log in. Resolved in one query keyed by carrier user rather than per
        | row, which would be an N+1 across the whole list.
        |
        | Only successful attempts count — a failed one says nothing about where
        | the real carrier is.
        */
        $lastLogins = collect();

        $carrierUserIds = $requests->pluck('carrier_user_id')->filter()->unique()->values();

        if ($carrierUserIds->isNotEmpty()) {
            $lastLogins = CarrierLoginAttempt::query()
                ->select('carrier_user_id', 'ip_address', 'created_at')
                ->whereIn('carrier_user_id', $carrierUserIds)
                ->where('outcome', CarrierLoginAttempt::OUTCOME_SUCCESS)

                // `id` breaks the tie: two sign-ins land in the same second
                // often enough (a retry, a second device) that ordering on the
                // timestamp alone would pick between them arbitrarily and could
                // report the older address as the latest.
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('carrier_user_id')
                ->map(fn ($attempts) => $attempts->first());
        }

        $rows = $requests->map(function (CarrierConnectRequest $connectRequest) use ($lastLogins) {
            $payload = (new CarrierConnectRequestResource($connectRequest))->resolve();

            $payload['invited_by'] = $connectRequest->user
                ? trim($connectRequest->user->first_name.' '.$connectRequest->user->last_name)
                : null;

            $lastLogin = $connectRequest->carrier_user_id
                ? $lastLogins->get($connectRequest->carrier_user_id)
                : null;

            $payload['last_login_ip'] = $lastLogin?->ip_address;
            $payload['last_login_at'] = $lastLogin?->created_at;

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
     *
     * The broker may ask for the invitation to go somewhere other than the
     * address on the carrier's FMCSA record — a dispatch or compliance inbox,
     * typically. That address is not trusted on the broker's word: nothing is
     * sent to it until the carrier approves it from their FMCSA inbox. See
     * approveAlternateEmail().
     */
    public function store(SendCarrierConnectRequest $request)
    {
        $user = $request->user();

        $validated = $request->validated();

        $carrier = $this->findCarrier($validated['row_id']);

        if (! $carrier) {
            return $this->error('Carrier not found in the carrier directory.', null, 404);
        }

        // Always read off the carrier record. The client picks a route, never
        // the registered address itself.
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

        $alternate = strtolower(trim((string) ($validated['email'] ?? '')));

        // An "alternate" that is really the registered address needs no
        // approval — it would be asking that inbox to approve itself.
        $wantsAlternate = ($validated['email_option'] ?? 'fmcsa') === 'alternate'
            && $alternate !== ''
            && $alternate !== strtolower($email);

        // Belt and braces over the form request's `email` rule, since this
        // address is what the invitation will eventually be mailed to.
        if ($wantsAlternate && ! filter_var($alternate, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Enter a valid email address.', null, 422);
        }

        /*
        | The document the carrier will be asked to sign at the last step.
        |
        | This is snapshotted onto the request, so sending an invitation before
        | the company has an agreement on file produced a request that could
        | never be signed: the carrier worked through all six steps only to be
        | told at the end that the broker had not uploaded anything, and
        | uploading one afterwards did not repair the requests already sent.
        |
        | Refusing here is the honest place to fail — before the carrier is
        | emailed and starts work that cannot be completed.
        */
        $agreement = BrokerAgreementDocument::forCompany($user->company_id)
            ->active()
            ->latest()
            ->first();

        if (! $agreement) {
            return $this->error(
                'Upload your broker agreement before sending onboarding invitations. '
                .'Without one the carrier cannot sign at the final step.',
                null,
                422
            );
        }

        $connectRequest = DB::transaction(function () use (
            $user, $carrier, $email, $agreement, $wantsAlternate, $alternate
        ) {

            // updateOrCreate on (company_id, carrier_row_id) means re-clicking
            // Connect resends the invitation and pushes the expiry out, rather
            // than colliding on the unique key.
            $connectRequest = CarrierConnectRequest::firstOrNew([
                'company_id' => $user->company_id,
                'carrier_row_id' => $carrier->row_id,
            ]);

            $isNew = ! $connectRequest->exists;

            if ($isNew) {
                $connectRequest->uuid = Str::uuid();
                $connectRequest->token = Str::random(64);
                $connectRequest->status = CarrierConnectRequest::STATUS_NEW;
            }

            $connectRequest->fill([
                'user_id' => $user->id,
                'carrier_dot_number' => $carrier->dot_number,
                'carrier_legal_name' => $carrier->legal_name ?: $carrier->dba_name,
                'carrier_phone' => $carrier->telephone,
                'agreement_document_id' => $agreement?->id,
            ]);

            if ($wantsAlternate) {
                /*
                | Park the address and start the approval clock. `sent_on` is
                | deliberately left alone: no invitation has gone out, so
                | starting the link's expiry here would burn the lifetime while
                | the carrier is still deciding — and on an existing request it
                | would silently extend an invitation nobody asked to resend.
                */
                $connectRequest->fill([
                    'carrier_email' => $connectRequest->carrier_email ?: $email,
                    'pending_email' => $alternate,
                    'pending_email_token' => Str::random(64),
                    'pending_email_requested_at' => now(),
                    'pending_email_approved_at' => null,
                ]);
            } else {
                // Choosing the registered address abandons any approval still
                // outstanding, so a stale request cannot be approved later and
                // silently redirect the invitation.
                $connectRequest->fill([
                    'carrier_email' => $email,
                    'pending_email' => null,
                    'pending_email_token' => null,
                    'pending_email_requested_at' => null,
                    'pending_email_approved_at' => null,
                    'sent_on' => now(),
                ]);
            }

            $connectRequest->save();

            /*
            | A carrier who already onboarded with another broker has proved
            | their phone, their ID, their bank and handed over their W-9 and
            | COI. None of that is broker-specific, so making them repeat it is
            | pure friction. Seed the new request from the old one and leave
            | them only the questionnaire and the agreement, which genuinely
            | belong to this broker.
            |
            | Only on creation: re-clicking Connect on an existing request must
            | not overwrite progress the carrier has since made here.
            */
            if ($isNew) {
                $this->prefillFromPreviousOnboarding($connectRequest);
            }

            return $connectRequest->fresh(['company']);
        });

        if ($wantsAlternate) {
            $this->afterResponse(
                fn () => $this->sendAlternateEmailApprovalMail($connectRequest, $email, $user)
            );

            return $this->respondWithRequest(
                $connectRequest,
                'Approval requested. We have emailed the carrier’s FMCSA-registered address to confirm sending the onboarding link to '.$alternate.'.',
                201
            );
        }

        $this->afterResponse(fn () => $this->sendInvitationMail($connectRequest, $user));

        return $this->respondWithRequest(
            $connectRequest,
            'Connection request sent. The carrier has been emailed an onboarding link.',
            201
        );
    }

    /**
     * The link in the approval email, opened from the carrier's FMCSA-registered
     * inbox. Reaching it is the carrier's consent to onboarding being run
     * through a different address, so this is the only thing that promotes the
     * pending address and releases the invitation to it.
     *
     * The token is single-use and cleared on approval, so a forwarded or
     * re-crawled link cannot re-point an invitation later.
     */
    public function approveAlternateEmail(string $token)
    {
        $connectRequest = CarrierConnectRequest::where('pending_email_token', $token)
            ->with('company')
            ->first();

        if (! $connectRequest || ! $connectRequest->pending_email) {
            return redirect()->away($this->frontendUrl('/carrier/email-approval?status=invalid'));
        }

        $lifetime = (int) config('carrier_connect.request_lifetime_hours', 72);

        if ($connectRequest->pending_email_requested_at?->copy()->addHours($lifetime)->isPast() ?? true) {
            return redirect()->away($this->frontendUrl('/carrier/email-approval?status=expired'));
        }

        $approvedEmail = $connectRequest->pending_email;

        $connectRequest->forceFill([
            'carrier_email' => $approvedEmail,
            'pending_email' => null,
            'pending_email_token' => null,
            'pending_email_approved_at' => now(),

            // The invitation goes out now, so this is where its clock starts.
            'sent_on' => now(),
        ])->save();

        // Resolved here rather than inside the callback so the deferred send
        // works from a model that is known-good at this point.
        $invitation = $connectRequest->fresh(['company']);
        $sender = $connectRequest->user;

        // The carrier is sitting on a redirect: making them watch an SMTP
        // handshake before their browser moves is the same stall store() had.
        $this->afterResponse(fn () => $this->sendInvitationMail($invitation, $sender));

        return redirect()->away($this->frontendUrl(
            '/carrier/email-approval?status=approved&email='.urlencode($approvedEmail)
        ));
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
    public function open(Request $request, string $token)
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

                // Where the carrier opened the invitation from. Captured on the
                // first touch and left alone afterwards, so it answers "where
                // did this onboarding come from" rather than drifting to
                // wherever they happened to reload it last.
                'onboarding_ip' => $request->ip(),
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

        // Backstop for a carrier who reached the wizard without passing through
        // open() — a bookmarked or forwarded link, say. Still first-touch-only,
        // so the recorded address does not move once it is known.
        if ($connectRequest->onboarding_ip === null) {
            $connectRequest->forceFill(['onboarding_ip' => $request->ip()])->save();
        }

        /*
        | Repair a request sent before the broker had an agreement on file.
        |
        | store() now refuses to send in that state, but invitations already out
        | there carry a null agreement and would strand the carrier at the last
        | step forever. If the company has since uploaded one, attach it — there
        | is nothing to overwrite, and the alternative is a carrier who can
        | never finish.
        */
        if ($connectRequest->agreement_document_id === null) {
            $agreement = BrokerAgreementDocument::forCompany($connectRequest->company_id)
                ->active()
                ->latest()
                ->first();

            if ($agreement) {
                $connectRequest->forceFill(['agreement_document_id' => $agreement->id])->save();
            }
        }

        $carrier = $this->findCarrier($connectRequest->carrier_row_id);

        $connectRequest->load(['agreementDocument', 'documents']);

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

    /**
     * Stream a file the carrier uploaded, back to the carrier.
     *
     * The broker-side equivalent, downloadFile(), is scoped to the broker's
     * company and sits behind a session — a carrier working through the wizard
     * has neither, so they had no way to check what they had actually attached.
     * Authorised the same way as every other carrier-facing endpoint: by the
     * invitation token, which is what proves whose onboarding this is.
     *
     * Served inline so a PDF or an image opens in the browser rather than
     * downloading, and marked no-store because these are compliance documents.
     */
    public function viewDocument(string $token, string $type)
    {
        $connectRequest = $this->resolveRequest($token);

        if (! $connectRequest) {
            abort(404);
        }

        [$disk, $path, $name] = $this->resolveFile($connectRequest, $type);

        if (! $disk || ! $path || ! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $storage = Storage::disk($disk);

        // Trust the stored file rather than the request: the type comes off the
        // URL, so deriving the content type from the object on disk keeps a
        // crafted request from dictating how the browser treats the response.
        $mime = $storage->mimeType($path) ?: 'application/octet-stream';

        return response()->stream(
            function () use ($storage, $path) {
                $stream = $storage->readStream($path);

                if ($stream) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.addslashes($name ?: 'document').'"',
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

        /*
        | A carrier who factors is paid by their factoring company, not by the
        | broker, so there is no payout account for the broker to collect — the
        | bank step stops applying the moment they say yes. Recorded as a skip
        | so the broker sees why it is not there, rather than as an unexplained
        | gap.
        |
        | Switching back to "no" clears it again: the bank step applies once
        | more, and a stale skip would let them past a step that now counts.
        */
        if ($usesFactoring) {
            if ($connectRequest->stripe_verified_at === null) {
                $attributes['bank_skipped_at'] = now();
            }
        } elseif ($connectRequest->bank_skipped_at !== null) {
            $attributes['bank_skipped_at'] = null;
        }

        $connectRequest->forceFill($attributes)->save();

        return $this->respondWithRequest($connectRequest, 'Factoring details saved.');
    }

    /**
     * Records that the carrier chose to move past the government ID or bank
     * step without completing it.
     *
     * Only these two are skippable. The phone check, the questionnaire and the
     * agreement are not: the first is what proves we are talking to the
     * carrier, and the other two are the broker's own requirements.
     */
    public function skipStep(CarrierSkipStepRequest $request)
    {
        $data = $request->validated();

        $connectRequest = $this->resolveRequest($data['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        // Skipping something already done would throw away a real verification.
        if ($data['step'] === 'identity') {
            if ($connectRequest->didit_status === 'Approved') {
                return $this->respondWithRequest($connectRequest, 'Your ID is already verified.');
            }

            $connectRequest->forceFill(['identity_skipped_at' => now()])->save();

            return $this->respondWithRequest($connectRequest, 'Government ID step skipped.');
        }

        if ($data['step'] === 'eld') {
            // Same reasoning as the ID check: a carrier who has already linked
            // a provider should not be able to throw that away by pressing the
            // skip link on a stale page.
            if ($connectRequest->eld_connected_at !== null) {
                return $this->respondWithRequest($connectRequest, 'Your ELD is already connected.');
            }

            $connectRequest->forceFill(['eld_skipped_at' => now()])->save();

            return $this->respondWithRequest($connectRequest, 'ELD step skipped.');
        }

        if ($connectRequest->stripe_verified_at !== null) {
            return $this->respondWithRequest($connectRequest, 'Your bank account is already connected.');
        }

        $connectRequest->forceFill(['bank_skipped_at' => now()])->save();

        return $this->respondWithRequest($connectRequest, 'Bank account step skipped.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 4 — ELD / telematics (Terminal)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Open the Terminal Link page for this carrier.
     *
     * The URL is minted here rather than in the browser because it carries the
     * publishable key, the consent template for this broker, and a state nonce
     * that has to be remembered server-side for the return leg to mean
     * anything.
     *
     * The carrier signs in to their provider on Terminal's page. Their provider
     * credentials never touch this application, which is exactly what the step
     * promises them on screen.
     */
    public function connectEld(CarrierConnectTokenRequest $request)
    {
        $connectRequest = $this->resolveRequest($request->validated()['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        if (! $this->eldConnections->isConfigured()) {
            Log::error('Terminal is not configured; cannot open the ELD connection page.');

            return $this->error('ELD connection is unavailable right now.', null, 503);
        }

        $url = $this->eldConnections->linkUrlFor(
            $connectRequest,

            // Terminal appends `result`, `token` and `state`; the `eld` flag is
            // ours, and is what tells the wizard which return leg this is.
            $this->frontendUrl('/carrier/connect/'.$connectRequest->token).'?eld=1'
        );

        if (! $url) {
            return $this->error('Could not open the ELD connection page. Please try again.', null, 502);
        }

        return $this->success(['url' => $url], 'ELD connection started.');
    }

    /**
     * Finish the connection the carrier just made.
     *
     * Exchanges the single-use public token for the connection token, dedupes
     * against a connection the carrier already has, records this broker's
     * consent, and queues the first fleet import.
     *
     * The import is deliberately not waited on: a large fleet takes minutes,
     * and the carrier has five more steps to get through.
     */
    public function verifyEld(CarrierEldVerifyRequest $request)
    {
        $data = $request->validated();

        $connectRequest = $this->resolveRequest($data['token']);

        if (! $connectRequest) {
            return $this->error('This onboarding link is no longer valid.', null, 404);
        }

        $connection = $this->eldConnections->completeFromPublicToken(
            $connectRequest,
            $data['public_token'],
            $data['state']
        );

        if (! $connection) {
            return $this->error(
                'Could not finish connecting your ELD. Please try again.',
                null,
                422
            );
        }

        return $this->respondWithRequest(
            $connectRequest->refresh(),
            'ELD connected. Your fleet is importing in the background.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 5 — the broker's own questions
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

    // ─────────────────────────────────────────────────────────────────────────
    // Step 5 — documents (W-9, COI)
    // ─────────────────────────────────────────────────────────────────────────

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
    // Step 6 — e-sign
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
     * Run something once the response is already on its way to the browser.
     *
     * Mail is the reason this exists. QUEUE_CONNECTION is `sync` and there is
     * no worker, so Mail::send() opens an SMTP connection to SendGrid inside
     * the request: the broker sat waiting on a handshake that has nothing to
     * do with the answer they asked for, and on a slow one the frontend gave
     * up first and reported a timeout for an invitation that had in fact been
     * saved and sent.
     *
     * terminating() rather than a queued job because it needs no worker and no
     * serialisation — the callback runs in this same process after
     * Response::send() has flushed, so it stays correct on `sync` and keeps
     * working unchanged if a real queue is introduced later.
     *
     * Anything deferred here must handle its own failures: by the time it runs
     * the status code is already sent and cannot be changed. Both mail helpers
     * catch and log, which is the behaviour we want — a delivery failure
     * must not discard the request that was just created.
     */
    private function afterResponse(callable $callback): void
    {
        app()->terminating($callback);
    }

    /**
     * Single exit point for every response that carries a connect request.
     *
     * The relations are loaded here rather than at each call site because
     * CarrierConnectRequestResource exposes `agreement_url` and `documents`
     * via whenLoaded — without this the keys silently disappear from the
     * step-completion responses.
     *
     * `documents` matters as much as the agreement: the wizard decides whether
     * a compliance slot is filled by looking for an entry in that array, so an
     * omitted key made every slot look empty. A carrier who had just uploaded
     * their W-9 was shown the empty dropzone again, with no name, no size and
     * no way to view what they had sent — the upload had in fact worked.
     */
    private function respondWithRequest(
        CarrierConnectRequest $connectRequest,
        string $message,
        int $code = 200
    ) {
        return $this->success(
            new CarrierConnectRequestResource(
                // eldConnection comes along so the ELD tile can report the
                // provider and the import's progress without a second call.
                $connectRequest->load(['agreementDocument', 'documents', 'eldConnection'])
            ),
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
        $rowId = trim($rowId);

        if ($rowId === '') {
            return null;
        }

        /*
        | Matched on dot_number, not row_id.
        |
        | `carriers` is a view over company_census_file which defines row_id as
        | CAST(dot_number AS CHAR) — an expression, not a column. `where row_id
        | = ?` therefore wraps the indexed column in a function, MySQL cannot
        | answer it from uk_dot, and it scans and casts the whole census file:
        | ~21 seconds per lookup against the live database, against ~0.6s for
        | the same carrier found by dot_number.
        |
        | row_id is only the dot number rendered as a string, so this returns
        | exactly the same row. Anything non-numeric falls back to the original
        | column, so no caller can break on it.
        |
        | This is what was left of "sending the invitation takes forever" after
        | the mail itself was moved off the request path — store() and load()
        | both pay this lookup, so the carrier felt it twice: once when the
        | broker pressed send, and again when they opened the wizard.
        |
        | Carrier::findByRowId() applies the same rule but cannot be used here,
        | because the authority eager-load is what supplies the MC number.
        */
        $query = Carrier::query()
            ->with('authority:carrier_authorities.dot_number,carrier_authorities.docket_number');

        $carrier = ctype_digit($rowId)
            ? $query->where('dot_number', (int) $rowId)->first()
            : $query->where('row_id', $rowId)->first();

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
    private function sendInvitationMail(CarrierConnectRequest $connectRequest, ?User $user): void
    {
        // Built from the request's own host rather than APP_URL, for the reason
        // set out in sendAlternateEmailApprovalMail(): APP_URL is not
        // necessarily an address that routes to this application.
        $connectUrl = url('/carrier/connect/'.$connectRequest->token);

        // Nullable because approval arrives from the carrier's inbox, with no
        // broker session behind it — and the teammate who sent the request may
        // since have been removed.
        $brokerName = trim(($user?->first_name ?? '').' '.($user?->last_name ?? ''))
            ?: $connectRequest->company->company_name;

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

    /**
     * Asks the carrier, at their FMCSA-registered address, to approve running
     * onboarding through a different inbox.
     *
     * This goes to the registered address and nowhere else — mailing the
     * alternate address at this point would defeat the check, since the whole
     * question is whether the carrier recognises it.
     */
    private function sendAlternateEmailApprovalMail(
        CarrierConnectRequest $connectRequest,
        string $fmcsaEmail,
        ?User $user
    ): void {
        /*
        | url() resolves against the host this request actually arrived on,
        | rather than APP_URL. The two differ in every local setup here — the
        | API is served on 127.0.0.1:8000 while APP_URL still reads
        | http://localhost — and a link built from APP_URL lands on a host that
        | does not route to Laravel at all, so clicking it appears to do
        | nothing. Same reasoning as the document URLs in
        | CarrierConnectRequestResource.
        */
        $approvalUrl = url('/carrier/connect/approve-email/'.$connectRequest->pending_email_token);

        $brokerName = trim(($user?->first_name ?? '').' '.($user?->last_name ?? ''))
            ?: $connectRequest->company->company_name;

        try {
            Mail::to($fmcsaEmail)->send(new CarrierAlternateEmailApprovalMail(
                $connectRequest,
                $approvalUrl,
                $brokerName,
                $connectRequest->pending_email
            ));
        } catch (\Throwable $e) {
            // Matches sendInvitationMail: the request is already saved, so a
            // mail failure is logged rather than losing the broker's work.
            Log::error('Carrier alternate email approval mail failed', [
                'connect_request' => $connectRequest->uuid,
                'email' => $fmcsaEmail,
                'error' => $e->getMessage(),
            ]);
        }

        if (app()->environment('local')) {
            Log::info('======= Carrier Alternate Email Approval =========');
            Log::info('To (FMCSA)', ['email' => $fmcsaEmail]);
            Log::info('Requested for', ['email' => $connectRequest->pending_email]);
            Log::info('Link', ['url' => $approvalUrl]);
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

    /**
     * Seeds a brand new request from the same carrier's most recent onboarding
     * with a different broker.
     *
     * What carries over is what belongs to the *carrier* — their verified
     * phone, their government ID check, their payout account, their compliance
     * paperwork. What does not carry over is what belongs to the *broker*: the
     * questionnaire, the agreement and its signature. Those are re-answered and
     * re-signed for every broker, which is the whole point of onboarding again.
     *
     * The email is also deliberately left unverified — the carrier still has to
     * open this broker's own invitation link, which is what proves they control
     * the address it was sent to.
     */
    private function prefillFromPreviousOnboarding(CarrierConnectRequest $connectRequest): void
    {
        $source = CarrierConnectRequest::query()
            ->where('carrier_row_id', $connectRequest->carrier_row_id)
            ->where('company_id', '!=', $connectRequest->company_id)
            ->where('id', '!=', $connectRequest->id)

            // Something worth copying. A request that never got past the
            // invitation has nothing to give.
            ->where(function ($query) {
                $query->whereNotNull('mobile_verified_at')
                    ->orWhereNotNull('stripe_verified_at')
                    ->orWhere('didit_status', 'Approved');
            })

            // A finished onboarding is the most complete source; failing that,
            // the most recently worked on.
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [CarrierConnectRequest::STATUS_COMPLETED])
            ->orderByDesc('updated_at')
            ->with('documents')
            ->first();

        if (! $source) {
            return;
        }

        $attributes = [
            'prefilled_from_request_id' => $source->id,
            'prefilled_at' => now(),

            // The number the carrier actually verified, which is not
            // necessarily the one on the FMCSA record.
            'carrier_phone' => $source->carrier_phone ?: $connectRequest->carrier_phone,
            'mobile_verified_at' => $source->mobile_verified_at,

            'didit_session_id' => $source->didit_session_id,
            'didit_status' => $source->didit_status,
            'didit_responded_at' => $source->didit_responded_at,
            'didit_response' => $source->didit_response,
            'didit_risk_flagged' => $source->didit_risk_flagged,
            'didit_registration_ip' => $source->didit_registration_ip,

            'stripe_express_account' => $source->stripe_express_account,
            'stripe_verified_at' => $source->stripe_verified_at,

            'uses_factoring_company' => $source->uses_factoring_company,
            'factoring_company_name' => $source->factoring_company_name,
            'factoring_answered_at' => $source->factoring_answered_at,

            // The portal login is the carrier's own and spans brokers, so the
            // new request points at the same account rather than provisioning
            // a second one.
            'carrier_user_id' => $source->carrier_user_id,
        ];

        /*
        | The factoring answer carries over, so the waiver it implies has to
        | come with it — otherwise a factoring carrier arrives with the question
        | already answered but the bank step still barring the way.
        |
        | A skip the carrier chose for themselves is deliberately NOT carried
        | over: passing on the ID check for one broker is not consent to pass on
        | it for the next, who may well want it done.
        */
        if ($source->uses_factoring_company && $source->stripe_verified_at === null) {
            $attributes['bank_skipped_at'] = now();
        }

        /*
        | Files are copied, never referenced. They live under a per-company
        | prefix, and deleteDocument() removes the underlying object — sharing a
        | path would let one broker's carrier delete a file the other broker's
        | request still points at.
        */
        if ($source->factoring_document_path) {
            $copied = $this->copyPrivateFile(
                $source->factoring_document_disk,
                $source->factoring_document_path,
                'carrier-factoring/'.$connectRequest->company_id,
                $connectRequest->uuid
            );

            if ($copied) {
                $attributes['factoring_document_disk'] = $copied['disk'];
                $attributes['factoring_document_path'] = $copied['path'];
                $attributes['factoring_document_name'] = $source->factoring_document_name;
            }
        }

        foreach ($source->documents as $document) {
            $copied = $this->copyPrivateFile(
                $document->disk,
                $document->path,
                'carrier-documents/'.$connectRequest->company_id,
                $connectRequest->uuid.'-'.$document->type
            );

            if (! $copied) {
                continue;
            }

            $connectRequest->documents()->updateOrCreate(
                ['type' => $document->type],
                [
                    'disk' => $copied['disk'],
                    'path' => $copied['path'],
                    'name' => $document->name,
                    'size' => $document->size,
                    'mime' => $document->mime,
                ]
            );
        }

        // status tracks the furthest step reached, so it has to reflect what
        // was just carried over or the request would read as untouched.
        if ($source->stripe_verified_at) {
            $attributes['status'] = CarrierConnectRequest::STATUS_BANK_VERIFIED;
        } elseif ($source->didit_status === 'Approved') {
            $attributes['status'] = CarrierConnectRequest::STATUS_ID_VERIFIED;
        } elseif ($source->mobile_verified_at) {
            $attributes['status'] = CarrierConnectRequest::STATUS_MOBILE_VERIFIED;
        }

        $connectRequest->forceFill($attributes)->save();

        // Sets documents_completed_at only if every required type made it
        // across, so a failed copy cannot mark the step done.
        $this->syncDocumentsCompletion($connectRequest);

        Log::info('Carrier onboarding prefilled from a previous broker', [
            'connect_request' => $connectRequest->uuid,
            'source_request' => $source->uuid,
            'documents_copied' => $connectRequest->documents()->count(),
        ]);
    }

    /**
     * Duplicates a stored upload so the new request owns its own copy.
     *
     * A missing source or a storage failure returns null rather than throwing:
     * the carrier can always upload the file again, which is a far better
     * outcome than the broker's Connect click failing outright.
     */
    private function copyPrivateFile(
        ?string $disk,
        ?string $path,
        string $directory,
        string $prefix
    ): ?array {
        if (! $disk || ! $path) {
            return null;
        }

        try {
            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }

            $targetDisk = config('filesystems.default');

            $extension = pathinfo($path, PATHINFO_EXTENSION);

            $name = $prefix.'-'.now()->format('YmdHis').'-'.Str::random(6)
                .($extension ? '.'.$extension : '');

            $target = rtrim($directory, '/').'/'.$name;

            Storage::disk($targetDisk)->put($target, Storage::disk($disk)->get($path));

            return ['disk' => $targetDisk, 'path' => $target];
        } catch (\Throwable $e) {
            Log::warning('Could not copy a carrier upload while prefilling', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
        if (! $this->sms->isConfigured()) {
            Log::error('Telnyx is not configured; cannot send onboarding OTP.');

            // In local the code has just been written to the log, so the step is
            // still testable. Anywhere else, reporting success for a message
            // that was never sent is worse than failing — it leaves the carrier
            // waiting for a code that will not arrive.
            return app()->environment('local');
        }

        return $this->sms->send($to, $body, 'onboarding OTP');
    }

    /**
     * FMCSA phone numbers arrive as bare digits, Telnyx wants E.164.
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

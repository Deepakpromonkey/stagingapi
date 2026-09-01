<?php

use App\Http\Controllers\Api\ScoringWeightController;
use App\Http\Controllers\Api\V1\Agreement\AgreementDocumentController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SignupOtpController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\CarrierPortal\CarrierAuthController;
use App\Http\Controllers\Api\V1\CarrierPortal\CarrierBrokerController;
use App\Http\Controllers\Api\V1\CarrierPortal\CarrierDocumentController;
use App\Http\Controllers\Api\V1\CarrierPortal\CarrierLoadController;
use App\Http\Controllers\Api\V1\CarrierPortal\CarrierPasswordResetController;
use App\Http\Controllers\Api\V1\CarrierPortal\CarrierProfileController;
use App\Http\Controllers\Api\V1\CarrierPortal\CarrierUserController;
use App\Http\Controllers\Api\V1\CarrierReportController;
use App\Http\Controllers\Api\V1\CarrierShortlistController;
use App\Http\Controllers\Api\V1\AdvancedSearch\AdvancedCarrierSearchController;
use App\Http\Controllers\Api\V1\Company\CompanyController;
use App\Http\Controllers\Api\V1\Coi\CarrierInsuranceRequestController;
use App\Http\Controllers\Api\V1\Coi\InboundEmailWebhookController;
use App\Http\Controllers\Api\V1\Connect\CarrierConnectController;
use App\Http\Controllers\Api\V1\EmailTemplate\EmailTemplateController;
use App\Http\Controllers\Api\V1\Invitation\InvitationController;
use App\Http\Controllers\Api\V1\Ocr\OcrController;
use App\Http\Controllers\Api\V1\Role\RoleController;
use App\Http\Controllers\Api\V1\Shipment\ShipmentController;
use App\Http\Controllers\Api\V1\Shipment\ShipmentTemplateController;
use App\Http\Controllers\Api\V1\Subscription\BillingController;
use App\Http\Controllers\Api\V1\Subscription\StripeWebhookController;
use App\Http\Controllers\Api\V1\Subscription\SubscriptionController;
use App\Http\Controllers\Api\V1\User\UserController;
use App\Http\Controllers\Carrier\CarrierController;
use App\Http\Controllers\CarrierQuestionController;
use App\Http\Controllers\SearchHistoryController;
use App\Http\Controllers\Api\V1\Driver\DriverAuthController;
use App\Http\Controllers\Api\V1\Driver\DriverShipmentController;
use App\Http\Controllers\Api\V1\ShipmentChatController;
use App\Http\Middleware\EnsureBrokerUser;
use App\Http\Middleware\EnsureCarrierUser;
use App\Http\Middleware\EnsureDriver;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Services\Carrier\CarrierPortalDocumentService;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;

use App\Http\Controllers\DtPay\DtPayProfileController;
use App\Http\Controllers\DtPay\DtPayController;
use App\Http\Controllers\DtPay\DtPayTransactionsController;
use App\Http\Controllers\DtPay\DtPayDisputesController;

use App\Http\Controllers\DtPay\DtPayCarriersController;
use App\Http\Controllers\DtPay\DtPayAppealController;

use App\Http\Controllers\DtPay\GuestPayController;

Route::prefix('v1')->group(function () {

    Route::get('/health', function () {
        return response()->json([
            'status' => 'success',
            'message' => 'Backend is up and running!',
            'timestamp' => now(),
        ]);
    });

    // Public Routes
    Route::post('/signup', [AuthController::class, 'signup']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/invitations/accept', [InvitationController::class, 'accept']);
    Route::post('/verify-login-otp', [AuthController::class, 'verifyLoginOtp']);
    Route::get('/getCarrier', [ShipmentController::class, 'getCarrier']);

       // The pricing table shown right after signup. Public, because the plan
    // screen renders before the new account has finished authenticating.
    Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);

    // Stripe's own callback. Authorised by the signed payload, not a token.
    Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

    /*
    | The mail provider's callback for a reply to the insurance inbox. Public
    | for the same reason Stripe's is — the provider has no token — and
    | authorised by the shared secret it carries, on a header or the query
    | string. See config/coi_insurance.php.
    */
    Route::post('/webhooks/inbound-email', [InboundEmailWebhookController::class, 'handle']);

    /*
    | Signup verification. Public by necessity — there is no account to
    | authenticate against yet — so the limits carry the weight instead.
    |
    | Sending is the tighter of the two because every phone send is an SMS
    | somebody pays for. Verifying is looser: a user mistyping a code should
    | not be locked out by the same budget that guards the gateway, and wrong
    | guesses are already bounded per code by otp_max_attempts.
    */
    Route::post('/signup/otp/send', [SignupOtpController::class, 'send'])
        ->middleware('throttle:6,1');

    Route::post('/signup/otp/verify', [SignupOtpController::class, 'verify'])
        ->middleware('throttle:20,1');

    // PHMSA, SmartWay, CARB compliance list import
    Route::post('/carrier-compliance/import', [\App\Http\Controllers\Api\V1\CarrierComplianceController::class, 'importCsv']);

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
        Route::post('/verify-forgot-password-otp', [PasswordResetController::class, 'verifyResetOtp']);
        Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
    });

    /*
    | Carrier onboarding, carrier side.
    |
    | The carrier is not a user of the system, so these are public and
    | authorised by the 64 character invitation token in the request body.
    */
    Route::prefix('carrier-connect')->group(function () {

        // The broker's agreement, streamed by the API. A GET so the PDF viewer
        // can fetch it directly, and proxied rather than linked straight to S3
        // because the bucket sends no CORS headers.
        Route::get('/agreement/{token}', [CarrierConnectController::class, 'agreement'])
            ->middleware('throttle:60,1');

        // A file the carrier uploaded, streamed back to them. A GET with the
        // token in the path, like the agreement above, so the browser can open
        // it directly — the broker-side download is company-scoped and behind a
        // session, which a carrier does not have.
        Route::get('/documents/{token}/{type}', [CarrierConnectController::class, 'viewDocument'])
            ->middleware('throttle:60,1');

        // Reading state and stepping through the wizard. Generous, because a
        // carrier working through six steps makes a fair few of these.
        Route::middleware('throttle:60,1')->group(function () {
            Route::post('/load', [CarrierConnectController::class, 'load']);
            Route::post('/identity/start', [CarrierConnectController::class, 'startIdentityVerification']);
            Route::post('/identity/verify', [CarrierConnectController::class, 'checkIdentityVerification']);
            Route::post('/stripe/connect', [CarrierConnectController::class, 'connectStripe']);
            Route::post('/stripe/verify', [CarrierConnectController::class, 'verifyStripe']);
            Route::post('/factoring', [CarrierConnectController::class, 'saveFactoring']);
            Route::post('/skip', [CarrierConnectController::class, 'skipStep']);
            Route::post('/questions', [CarrierConnectController::class, 'questions']);
            Route::post('/answers', [CarrierConnectController::class, 'saveAnswers']);
            Route::post('/documents', [CarrierConnectController::class, 'uploadDocument']);
            Route::post('/documents/{type}/remove', [CarrierConnectController::class, 'deleteDocument']);
            Route::post('/esign', [CarrierConnectController::class, 'esign']);
        });

        // Sending an SMS costs money, so resends get their own tight bucket.
        Route::post('/otp/send', [CarrierConnectController::class, 'sendOtp'])
            ->middleware('throttle:10,1');

        // Guessing is already bounded by otp_attempts; this just stops someone
        // hammering the endpoint to burn through codes.
        Route::post('/otp/verify', [CarrierConnectController::class, 'verifyOtp'])
            ->middleware('throttle:15,1');

        // Didit calls this server to server, so it carries no session.
        Route::post('/identity/webhook', [CarrierConnectController::class, 'identityWebhook']);
    });

    /*
    | Driver app.
    |
    | Drivers sign in with their phone number and a texted code — there is no
    | password and no signup. Being named on a shipment is what makes someone a
    | driver here, so the account is created on first successful sign-in.
    |
    | Same Sanctum guard as everyone else, so EnsureDriver keeps broker and
    | carrier tokens out of these routes.
    */
    Route::prefix('driver')->group(function () {

        // Tight bucket: each call sends an SMS, which costs money, and the
        // endpoint is unauthenticated by nature.
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('/auth/request-otp', [DriverAuthController::class, 'requestOtp']);
            Route::post('/auth/verify-otp', [DriverAuthController::class, 'verifyOtp']);
        });

        Route::middleware(['auth:sanctum', EnsureDriver::class])->group(function () {
            Route::get('/me', [DriverAuthController::class, 'me']);
            Route::post('/logout', [DriverAuthController::class, 'logout']);

            // The driver's loads, and the chat on each.
            Route::get('/shipments', [DriverShipmentController::class, 'index']);
            Route::get('/shipments/{uuid}/messages', [ShipmentChatController::class, 'index']);
            Route::post('/shipments/{uuid}/messages', [ShipmentChatController::class, 'store']);
        });
    });

    /*
    | Carrier portal.
    |
    | The account behind these is created by the onboarding wizard, not here —
    | there is no signup. Carriers authenticate through the same Sanctum guard
    | as broker staff but are a different model entirely, so EnsureCarrierUser
    | keeps a broker token out of the portal, and EnsureBrokerUser (below) keeps
    | a carrier token out of the broker API.
    */
    Route::prefix('carrier-portal')->group(function () {

        // Sign-in is two steps unless the device is trusted: credentials, then
        // the code mailed to the carrier.
        Route::middleware('throttle:10,1')->group(function () {

            Route::post('/login', [CarrierAuthController::class, 'login']);
            Route::post('/verify-login-otp', [CarrierAuthController::class, 'verifyLoginOtp']);

            Route::post('/forgot-password', [CarrierPasswordResetController::class, 'forgotPassword']);
            Route::post('/verify-forgot-password-otp', [CarrierPasswordResetController::class, 'verifyResetOtp']);
            Route::post('/reset-password', [CarrierPasswordResetController::class, 'resetPassword']);
        });

        Route::middleware(['auth:sanctum', EnsureCarrierUser::class, EnsurePasswordChanged::class])
            ->group(function () {
                Route::get('/me', [CarrierAuthController::class, 'me']);
                Route::post('/logout', [CarrierAuthController::class, 'logout']);
                Route::post('/change-password', [CarrierAuthController::class, 'changePassword']);

                // Remembered devices, and the sign-in trail behind them.
                Route::get('/devices', [CarrierAuthController::class, 'devices']);
                Route::post('/devices/forget', [CarrierAuthController::class, 'forgetDevice']);
                Route::get('/login-history', [CarrierAuthController::class, 'loginHistory']);

                // The carrier's own record: company card and onboarding
                // completeness, and the brokers they have onboarded with.
                Route::middleware(PermissionMiddleware::using('view-own-carrier-profile'))
                    ->group(function () {
                        Route::get('/profile', [CarrierProfileController::class, 'show']);
                        Route::get('/brokers', [CarrierBrokerController::class, 'index']);

                        // The picture is the only thing the profile screen
                        // writes. Name, DOT/MC, authority and company details
                        // are FMCSA and onboarding data — read-only here, for
                        // every seat, owner included.
                        Route::post('/profile/photo', [CarrierProfileController::class, 'updatePhoto']);
                        Route::delete('/profile/photo', [CarrierProfileController::class, 'deletePhoto']);

                        // The paperwork they handed over during onboarding,
                        // back out again. Streamed, never linked — the files
                        // sit on the private disk.
                        Route::get('/documents', [CarrierDocumentController::class, 'index']);
                        Route::get(
                            '/documents/{connectionUuid}/{type}/download',
                            [CarrierDocumentController::class, 'download']
                        )->whereIn('type', CarrierPortalDocumentService::downloadableTypes());
                    });

                /*
                | The loads brokers have put this carrier on, and the stops on
                | each. Read-only: the shipment is the broker's record.
                */
                Route::middleware(PermissionMiddleware::using('view-assigned-loads'))
                    ->group(function () {
                        Route::get('/loads', [CarrierLoadController::class, 'index']);
                        Route::get('/loads/{uuid}', [CarrierLoadController::class, 'show']);
                    });

                /*
                | The carrier's own team. Everyone in the account can see who
                | else is in it; only the Carrier Owner/Admin seat can change
                | anything — user management is one of the sensitive writes
                | that is deliberately not delegable (config/carrier_rbac.php).
                */
                Route::get('/users', [CarrierUserController::class, 'index']);

                Route::middleware(PermissionMiddleware::using('manage-carrier-users'))
                    ->group(function () {
                        Route::get('/roles', [CarrierUserController::class, 'roles']);
                        Route::post('/users/invite', [CarrierUserController::class, 'invite']);
                        Route::put('/users/{uuid}', [CarrierUserController::class, 'update']);
                    });
            });
    });

    // Protected Routes
    Route::middleware(['auth:sanctum', EnsureBrokerUser::class, EnsurePasswordChanged::class])->group(function () {
        Route::post('/check-pro-number', [ShipmentController::class, 'checkProNumber']);

        // Session
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::post('/update-profile', [AuthController::class, 'updateProfile']);

        // csv import + export carriers
       Route::post('/carriers/bulk-import', [\App\Http\Controllers\Api\V1\CarrierImportController::class, 'bulkImport']);

      
       
       Route::get('/carriers/export', [\App\Http\Controllers\Api\V1\CarrierExportController::class, 'export']);

       // Blocked carriers
        Route::get('/blocked', [\App\Http\Controllers\Api\V1\CarrierBlockedController::class, 'index']);
        Route::post('/blocked', [\App\Http\Controllers\Api\V1\CarrierBlockedController::class, 'store']);
        Route::delete('/blocked', [\App\Http\Controllers\Api\V1\CarrierBlockedController::class, 'destroy']);

        // Company
        Route::get('/company', [CompanyController::class, 'show']);
        Route::put('/company', [CompanyController::class, 'update'])
            ->middleware(PermissionMiddleware::using('edit-company-profile-billing'));

        // Subscription — the monthly plan picked up straight after signup.
        Route::get('/subscription', [SubscriptionController::class, 'show']);

        Route::middleware(PermissionMiddleware::using('edit-company-profile-billing'))->group(function () {
            Route::post('/subscription/checkout', [SubscriptionController::class, 'checkout']);
            Route::post('/subscription/checkout/sync', [SubscriptionController::class, 'syncCheckout']);
            Route::post('/subscription/portal', [SubscriptionController::class, 'billingPortal']);
            Route::post('/subscription/enterprise-inquiry', [SubscriptionController::class, 'enterpriseInquiry']);
        });

        /*
        | Billing — everything after the plan is bought.
        |
        | Reading the account's own invoices and spend is gated on the billing
        | permission, same as changing the plan: an invoice names what the
        | company pays, which is not something every seat on a brokerage desk
        | should be able to read.
        */
        Route::middleware(PermissionMiddleware::using('edit-company-profile-billing'))->group(function () {

            Route::get('/billing', [BillingController::class, 'overview']);
            Route::get('/billing/report', [BillingController::class, 'report']);

            Route::get('/billing/invoices', [BillingController::class, 'invoices']);

            // Before the {invoice} route, or 'export' is read as an invoice
            // reference and answers 404.
            Route::get('/billing/invoices/export', [BillingController::class, 'exportInvoices']);

            Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice']);

            // The DollarTraq-branded PDF. Not JSON — it answers with the file.
            Route::get('/billing/invoices/{invoice}/download', [BillingController::class, 'downloadInvoice']);

            Route::post('/billing/invoices/{invoice}/email', [BillingController::class, 'emailInvoice']);

            // Cancel anytime. Ends at the close of the paid period by default.
            Route::post('/billing/subscription/cancel', [BillingController::class, 'cancel']);
            Route::post('/billing/subscription/resume', [BillingController::class, 'resume']);
        });

        // Team & roles
        Route::middleware(PermissionMiddleware::using(['manage-users-basic', 'manage-users-all']))->group(function () {

            Route::get('/users', [UserController::class, 'index']);

            // Seat changes, contact edits and switching someone off. What you
            // may set is bounded by your own seat — see UpdateUserRequest.
            Route::put('/users/{uuid}', [UserController::class, 'update']);

            // Soft delete. The person leaves the team but their history stays.
            Route::delete('/users/{uuid}', [UserController::class, 'destroy']);

            // A fresh temporary password by email, for an invite that never
            // arrived. Keyed by the user uuid the team list already holds.
            Route::post('/users/{uuid}/resend-invitation', [InvitationController::class, 'resend']);

            Route::get('/roles', [RoleController::class, 'index']);

            Route::post('/invitations', [InvitationController::class, 'store']);

        });

        // Scoring configuration
        Route::post('/scoring-weights', [ScoringWeightController::class, 'store'])
            ->middleware(PermissionMiddleware::using('edit-scoring-config'));

        Route::get('/scoring-weights/active', [ScoringWeightController::class, 'getActiveWeights']);
        Route::get('/scoring-weights/templates', [ScoringWeightController::class, 'getTemplates']);

        // Shipments
        Route::middleware(PermissionMiddleware::using('view-loads-tracking'))->group(function () {
            Route::get('/shipments', [ShipmentController::class, 'index']);
            Route::get('/shipments/{uuid}', [ShipmentController::class, 'detail']);
            Route::get('/shipment-templates', [ShipmentTemplateController::class, 'index']);
            Route::get('/shipment-templates/{tracking_number}', [ShipmentTemplateController::class, 'show']);
        });

        Route::middleware(PermissionMiddleware::using('book-assign-loads'))->group(function () {
            Route::post('/shipments', [ShipmentController::class, 'store']);
            Route::post('/shipments/{uuid}/stops', [ShipmentController::class, 'addStops']); 
        });

        /*
        | Broker side of the shipment chat. Gated on seeing the load at all —
        | talking to the driver about a shipment you cannot view makes no sense,
        | and there is no separate "may chat" permission to grant.
        */
        Route::middleware(PermissionMiddleware::using('view-loads-tracking'))->group(function () {
            Route::get('/shipments/{uuid}/messages', [ShipmentChatController::class, 'index']);
            Route::post('/shipments/{uuid}/messages', [ShipmentChatController::class, 'store']);
        });

        // Carrier search (reads the EC2 carrier database)
        Route::get('/carrier/search', [CarrierController::class, 'search']);
 
        Route::post('/carrier/advanced-filter', [AdvancedCarrierSearchController::class, 'filter']);

        Route::middleware(PermissionMiddleware::using('view-carrier-directory'))->group(function () {

            Route::get('/carrier/{dot}/trust-score', [CarrierController::class, 'index']);
            Route::get('/carriers/{dot}/risk', [CarrierController::class, 'show'])
                ->where('dot', '[0-9]+');
            Route::get('/carriers/{dot}/associations', [CarrierController::class, 'association']);

            // FMCSA's field-level change history for this carrier, read out of
            // the S3 export by byte range rather than from a table.
            Route::get('/carriers/{dot}/contact-history', [CarrierController::class, 'contactHistory'])
                ->where('dot', '[0-9]+');
            Route::get('/carrier/{dot}/vin-association', [CarrierController::class, 'vinAssociation']);
            Route::post('/carrier/detail/{rowid}', [CarrierController::class, 'detail']);

             // Check DOT compliance (PHMSA, CARB, SmartWay)
            Route::post('/carrier-compliance/check', [\App\Http\Controllers\Api\V1\CarrierComplianceController::class, 'checkCompliance']);

        });

        // OCR
        Route::post('/ocr-data', [OcrController::class, 'getOcrData']);

        /*
        | Chasing a carrier's insurance agency for current COI details. Scoped
        | to the caller's company, so a colleague who opens the same carrier
        | profile sees the request someone else already raised rather than
        | mailing the agency a second time.
        */
        Route::get('/carrier-insurance-requests', [CarrierInsuranceRequestController::class, 'index']);
        Route::post('/carrier-insurance-requests', [CarrierInsuranceRequestController::class, 'store']);
        Route::get('/carrier-insurance-requests/responses/{uuid}', [CarrierInsuranceRequestController::class, 'response']);
        Route::get('/carriers/{dot}/insurance-request', [CarrierInsuranceRequestController::class, 'show'])
            ->where('dot', '[0-9]+');

        /*
        | Carrier onboarding, broker side. Scoped to the caller's company, so
        | any teammate can see and resend a request a colleague started.
        */
        Route::get('/carrier-connect', [CarrierConnectController::class, 'index']);

        Route::post('/carrier-connect', [CarrierConnectController::class, 'store'])
            ->middleware(PermissionMiddleware::using('send-invitation-approved-carriers'));
        Route::get('/carrier-connect/{uuid}/files/{type}', [CarrierConnectController::class, 'downloadFile']);

        // shortlist carriers
        Route::get('/shortlist', [CarrierShortlistController::class, 'index']);
        Route::post('/shortlist', [CarrierShortlistController::class, 'store']);
        Route::delete('/shortlist', [CarrierShortlistController::class, 'destroy']);
        Route::get('/carrier-reports/incidents', [CarrierReportController::class, 'incidents']);
        Route::get('/carrier-reports', [CarrierReportController::class, 'index']);
        Route::post('/carrier-reports', [CarrierReportController::class, 'store']);

        // Evidence attached to a report. Streamed by the API rather than linked
        // to, because the disk is private and entitlement is per report.
        Route::get('/carrier-reports/documents/{uuid}', [CarrierReportController::class, 'download']);
        // history logs
        Route::get('/search-history', [SearchHistoryController::class, 'index']);
        Route::post('/search-history', [SearchHistoryController::class, 'store']);

        // Broker agreement documents (stored on S3)
        Route::get('/agreement-documents', [AgreementDocumentController::class, 'index']);
        Route::get('/agreement-documents/{uuid}', [AgreementDocumentController::class, 'show']);

        Route::middleware(PermissionMiddleware::using('edit-carrier-agreements'))->group(function () {
            Route::post('/agreement-documents', [AgreementDocumentController::class, 'store']);
            Route::post('/agreement-documents/{uuid}', [AgreementDocumentController::class, 'update']);
            Route::patch('/agreement-documents/{uuid}/status', [AgreementDocumentController::class, 'toggleStatus']);
            Route::delete('/agreement-documents/{uuid}', [AgreementDocumentController::class, 'destroy']);
        });

        // Email templates (rich text, per company)
        Route::get('/email-templates/variables', [EmailTemplateController::class, 'variables']);
        Route::get('/email-templates', [EmailTemplateController::class, 'index']);
        Route::get('/email-templates/{uuid}', [EmailTemplateController::class, 'show']);

        Route::middleware(PermissionMiddleware::using('edit-carrier-agreements'))->group(function () {
            Route::post('/email-templates', [EmailTemplateController::class, 'store']);
            Route::put('/email-templates/{uuid}', [EmailTemplateController::class, 'update']);
            Route::patch('/email-templates/{uuid}/status', [EmailTemplateController::class, 'toggleStatus']);
            Route::post('/email-templates/{uuid}/preview', [EmailTemplateController::class, 'preview']);
            Route::delete('/email-templates/{uuid}', [EmailTemplateController::class, 'destroy']);
        });

        // Carrier questions
        Route::get('/carrier-questions', [CarrierQuestionController::class, 'index']);
        Route::post('/carrier-questions', [CarrierQuestionController::class, 'store']);
        Route::put('/carrier-questions/{id}', [CarrierQuestionController::class, 'update']);
        Route::delete('/carrier-questions/{id}', [CarrierQuestionController::class, 'destroy']);
    });

    /*
    DT Pay
    */
    Route::middleware('auth:sanctum')->group(function () {

        /*
        Profile
        */
        Route::post('/dt-pay/profile/init', [DtPayProfileController::class, 'init']);

        Route::post('/dt-pay/profile/methods/attach', [DtPayProfileController::class, 'attachPaymentMethods']);

        Route::post('/dt-pay/stats', [DtPayController::class, 'brokerStats']);

        Route::post('/dt-pay/payment/auto/loads', [DtPayController::class, 'fetchLoads']);

        Route::post('/dt-pay/payment/auto/sources', [DtPayController::class, 'fetchSources']);
        Route::post('/dt-pay/payment/auto/fund', [DtPayController::class, 'initFunding']);

        Route::post('/dt-pay/payment/auto/finish', [DtPayController::class, 'paymentFinish']);
        Route::post('/dt-pay/payment/manual/finish', [DtPayController::class, 'paymentManualFinish']);

        Route::post('/dt-pay/payment/intent', [DtPayController::class, 'paymentIntent']);
        Route::post('/dt-pay/payment/calculate', [DtPayController::class, 'calculateAmounts']);

        Route::post('/dt-pay/payment/pay', [DtPayController::class, 'makePayment']);

        Route::post('/dt-pay/transactions/init', [DtPayController::class, 'initTransaction']);

        Route::post('/dt-pay/payment/manual/init', [DtPayController::class, 'initManualTransaction']);
        Route::post('/dt-pay/payment/manual/submit', [DtPayController::class, 'submitManualTransaction']);
        Route::post('/dt-pay/payment/manual/carrier/search', [DtPayController::class, 'manualCarrierSearch']);
        Route::post('/dt-pay/payment/manual/carrier/update', [DtPayController::class, 'manualCarrierUpdate']);
        Route::post('/dt-pay/payment/manual/fund', [DtPayController::class, 'initManualFunding']);

        /*
        Transactions
        */
        Route::post('/dt-pay/transactions', [DtPayTransactionsController::class, 'listTransactions']);
        Route::post('/dt-pay/transactions/load', [DtPayTransactionsController::class, 'loadTransaction']);

        Route::post('/dt-pay/transactions/refund', [DtPayTransactionsController::class, 'refundPayment']);
        Route::post('/dt-pay/transactions/hold', [DtPayTransactionsController::class, 'holdPayment']);

        Route::post('/dt-pay/transactions/release', [DtPayTransactionsController::class, 'releasePayment']);

        /*
        Disputes
        */
        Route::post('/dt-pay/disputes/init', [DtPayDisputesController::class, 'init']);
        Route::post('/dt-pay/disputes/submit', [DtPayDisputesController::class, 'submitDispute']);

        /*
        Carriers
        */
        Route::post('/dt-pay/carriers/transactional_loads', [DtPayCarriersController::class, 'transactionalLoads']);
        Route::post('/dt-pay/carriers/pod/init', [DtPayCarriersController::class, 'initPod']);
        Route::post('/dt-pay/carriers/pod/upload', [DtPayCarriersController::class, 'uploadPod']);
        Route::post('/dt-pay/carriers/transaction/fetch', [DtPayCarriersController::class, 'fetchTransaction']);

        Route::post('/dt-pay/carriers/appeal/init', [DtPayAppealController::class, 'init']);
        Route::post('/dt-pay/carriers/appeal/submit', [DtPayAppealController::class, 'submitAppeal']);
    });

    /*
    DT Pay Guest Pay
    */
    Route::get('/guest-pay/carrier/search', [CarrierController::class, 'search']);

    // Route::post('/guest-pay/carrier/search', [GuestPayController::class, 'manualCarrierSearch']);
    Route::post('/guest-pay/transaction/load', [GuestPayController::class, 'loadTransaction']);
    Route::post('/guest-pay/payment/init', [GuestPayController::class, 'initGuestPay']);
    Route::post('/guest-pay/load/submit', [GuestPayController::class, 'loadSubmission']);
    Route::post('/guest-pay/info/submit', [GuestPayController::class, 'handlePersonalSubmit']);
});

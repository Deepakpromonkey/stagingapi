<?php

use App\Http\Controllers\Api\ScoringWeightController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\CarrierShortlistController;
use App\Http\Controllers\Api\V1\Company\CompanyController;
use App\Http\Controllers\Api\V1\Invitation\InvitationController;
use App\Http\Controllers\Api\V1\Ocr\OcrController;
use App\Http\Controllers\Api\V1\Role\RoleController;
use App\Http\Controllers\Api\V1\Shipment\ShipmentController;
use App\Http\Controllers\Api\V1\Shipment\ShipmentTemplateController;
use App\Http\Controllers\Api\V1\User\UserController;
use App\Http\Controllers\SearchHistoryController;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CarrierQuestionController;
use Spatie\Permission\Middleware\PermissionMiddleware;
use App\Http\Controllers\Api\V1\Driver\DriverTrackingController; 

Route::prefix('v1')->group(function () {

    Route::get('/health', function () {
        return response()->json([
            'status' => 'success',
            'message' => 'Backend is up and running!',
            'timestamp' => now()
        ]);
    });

    // Public Routes
    Route::post('/signup', [AuthController::class, 'signup']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/invitations/accept', [InvitationController::class, 'accept']);
    Route::post('/verify-login-otp', [AuthController::class, 'verifyLoginOtp']);
    Route::get('/getCarrier', [ShipmentController::class, 'getCarrier']);


    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
        Route::post('/verify-forgot-password-otp', [PasswordResetController::class, 'verifyResetOtp']);
        Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
    });

    // Protected Routes
    Route::middleware(['auth:sanctum', EnsurePasswordChanged::class])->group(function () {

        // Session
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);

        // Company
        Route::get('/company', [CompanyController::class, 'show']);
        Route::put('/company', [CompanyController::class, 'update'])
            ->middleware(PermissionMiddleware::using('edit-company-profile-billing'));

        // Team & roles
        Route::middleware(PermissionMiddleware::using(['manage-users-basic', 'manage-users-all']))->group(function () {

            Route::get('/users', [UserController::class, 'index']);

            Route::get('/roles', [RoleController::class, 'index']);

            Route::post('/invitations', [InvitationController::class, 'store']);

        });

        // Scoring configuration
        Route::post('/scoring-weights', [ScoringWeightController::class, 'store'])
            ->middleware(PermissionMiddleware::using('edit-scoring-config'));

        Route::get('/scoring-weights/active', [ScoringWeightController::class, 'getActiveWeights']);
        Route::get('/scoring-weights/templates', [ScoringWeightController::class, 'getTemplates']);

        // Shipments & Tracking
        Route::middleware(PermissionMiddleware::using('view-loads-tracking'))->group(function () {
            Route::get('/shipments', [ShipmentController::class, 'index']);
            Route::get('/shipments/{uuid}', [ShipmentController::class, 'detail']);
            Route::get('/shipment-templates', [ShipmentTemplateController::class, 'index']);
            Route::get('/shipment-templates/{tracking_number}', [ShipmentTemplateController::class, 'show']);
            
            // <-- ADDED THE DRIVER TRACKING ROUTE HERE -->
            Route::put('/drivers/{uuid}/tracking-interval', [DriverTrackingController::class, 'updateInterval']);
        });

        Route::middleware(PermissionMiddleware::using('book-assign-loads'))->group(function () {
            Route::post('/shipments', [ShipmentController::class, 'store']);
            Route::get('/shipments/{uuid}/locations', [ShipmentController::class, 'getLocations']);
            Route::post('/shipments/{uuid}/stops', [ShipmentController::class, 'addStops']);
        });

        // OCR
        Route::post('/ocr-data', [OcrController::class, 'getOcrData']);

        // shortlist carriers
        Route::get('/shortlist', [CarrierShortlistController::class, 'index']);
        Route::post('/shortlist', [CarrierShortlistController::class, 'store']);
        Route::delete('/shortlist', [CarrierShortlistController::class, 'destroy']);

        // history logs
        Route::get('/search-history', [SearchHistoryController::class, 'index']);
        Route::post('/search-history', [SearchHistoryController::class, 'store']);

        // Carrier questions
        Route::get('/carrier-questions', [CarrierQuestionController::class, 'index']);
        Route::post('/carrier-questions', [CarrierQuestionController::class, 'store']);
        Route::put('/carrier-questions/{id}', [CarrierQuestionController::class, 'update']);
        Route::delete('/carrier-questions/{id}', [CarrierQuestionController::class, 'destroy']);
        
        Route::post('/check-pro-number', [ShipmentController::class, 'checkProNumber']);
    });
});
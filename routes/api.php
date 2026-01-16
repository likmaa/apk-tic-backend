<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\TripsController;

// Health check endpoint (public)
Route::get('/health', fn() => response()->json(['status' => 'ok', 'timestamp' => now()]));

Route::prefix('auth')->group(function () {
    Route::post('/request-otp', [OtpController::class, 'requestOtp'])->middleware('throttle:otp');
    Route::post('/verify-otp', [OtpController::class, 'verifyOtp'])->middleware('throttle:otp');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [OtpController::class, 'logout']);
        Route::get('/me', [OtpController::class, 'me']);
        Route::put('/profile', [OtpController::class, 'updateProfile']);
    });
});

// Role-based route groups (scaffolding)
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\DriverModerationController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\PricingController;
use App\Http\Controllers\Admin\RidesController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\NotificationsController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\GeocodingController;
use App\Http\Controllers\VoiceController;
use App\Http\Controllers\RatingsController;
use App\Http\Controllers\PassengerAddressController;
use App\Http\Controllers\DriverProfileController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\PassengerLineController;

Route::prefix('admin')->group(function () {
    // Admin authentication (email/phone + password)
    Route::post('/login', [AdminAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'role:admin,developer'])->group(function () {
        // Authenticated admin endpoints
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
        // Health check
        Route::get('/ping', fn() => response()->json(['ok' => true, 'area' => 'admin']));
        Route::get('/drivers/{id}/profile', [DriverModerationController::class, 'showProfile']);

        // Driver moderation
        Route::get('/drivers/pending', [DriverModerationController::class, 'indexPending']);
        Route::get('/drivers/approved', [DriverModerationController::class, 'indexApproved']);
        Route::get('/drivers/online', [DriverModerationController::class, 'online']);
        Route::patch('/drivers/{id}/status', [DriverModerationController::class, 'updateStatus']);
        Route::get('/drivers/{id}/location', [DriverModerationController::class, 'location']);
        Route::get('/drivers/{id}/profile', [DriverModerationController::class, 'showProfile']);
        Route::post('/drivers/{id}/force-offline', [DriverModerationController::class, 'forceOffline']);

        // Users
        Route::post('/users', [UsersController::class, 'store']);
        Route::get('/users', [UsersController::class, 'index']);
        Route::get('/users/{id}', [UsersController::class, 'show']);
        Route::patch('/users/{id}', [UsersController::class, 'update']);
        Route::delete('/users/{id}', [UsersController::class, 'destroy']);

        // Pricing
        Route::get('/pricing', [PricingController::class, 'get']);
        Route::put('/pricing', [PricingController::class, 'update']);

        // Rides
        Route::get('/rides', [RidesController::class, 'index']);
        Route::post('/rides/{id}/cancel', [RidesController::class, 'cancel']);
        Route::get('/rides/status-breakdown', [RidesController::class, 'statusBreakdown']);
        Route::get('/passengers/{id}/rides', [RidesController::class, 'byPassenger']);

        // Finance
        Route::get('/finance/summary', [FinanceController::class, 'summary']);
        Route::get('/finance/transactions', [FinanceController::class, 'transactions']);

        // Wallet admin helpers
        Route::post('/users/{id}/wallet/reset', [\App\Http\Controllers\WalletController::class, 'adminReset']);

        // Notifications
        Route::post('/notifications/send', [\App\Http\Controllers\Api\Admin\NotificationController::class, 'store']);
        Route::get('/notifications/history', [\App\Http\Controllers\Api\Admin\NotificationController::class, 'index']);

        // Moderation (accounts, reports)
        Route::get('/moderation/queue', [ModerationController::class, 'queue']);
        Route::get('/moderation/logs', [ModerationController::class, 'logs']);

        Route::get('/stats/drivers/daily', [StatsController::class, 'driversDaily']);
        Route::get('/stats/drivers/daily/global', [StatsController::class, 'driversDailyGlobal']);
        Route::get('/stats/drivers/daily/top', [StatsController::class, 'topDriversDaily']);
        Route::get('/stats/overview', [StatsController::class, 'overview']);

        // Metrics & Analytics
        Route::get('/metrics', [\App\Http\Controllers\Admin\MetricsController::class, 'index']);
        Route::post('/webhooks/moneroo', [\App\Http\Controllers\Api\MonerooWebhookController::class, 'handle']);

        // Settings
        Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index']);
        Route::post('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update']);

        // Driver Wallet & Debt Management
        Route::get('/drivers/debts', [\App\Http\Controllers\Admin\WalletController::class, 'driversDebts']);
        Route::post('/wallets/{walletId}/adjust', [\App\Http\Controllers\Admin\WalletController::class, 'adjustBalance']);
        Route::get('/wallets/{walletId}/transactions', [\App\Http\Controllers\Admin\WalletController::class, 'transactions']);
        Route::post('/drivers/{driverId}/block', [\App\Http\Controllers\Admin\WalletController::class, 'blockDriver']);
        Route::post('/drivers/{driverId}/unblock', [\App\Http\Controllers\Admin\WalletController::class, 'unblockDriver']);
    });

    Route::middleware(['auth:sanctum', 'role:developer'])->group(function () {
        Route::get('/dev/logs', [\App\Http\Controllers\Admin\DeveloperController::class, 'logs']);
        Route::post('/dev/reset-data', [\App\Http\Controllers\Admin\DeveloperController::class, 'resetData']);
        Route::post('/dev/purge-stats', [\App\Http\Controllers\Admin\DeveloperController::class, 'purgeStats']);
        Route::post('/dev/clear-cache', [\App\Http\Controllers\Admin\DeveloperController::class, 'clearCache']);


        // Analytics (developer only)
        Route::get('/analytics/reconnections', [\App\Http\Controllers\Admin\AnalyticsController::class, 'reconnections']);
    });

    // Moderation actions (admin + developer)
    Route::middleware(['auth:sanctum', 'role:admin,developer'])->group(function () {
        Route::post('/moderation/{userId}/suspend', [\App\Http\Controllers\Admin\ModerationController::class, 'suspend']);
        Route::post('/moderation/{userId}/ban', [\App\Http\Controllers\Admin\ModerationController::class, 'ban']);
        Route::post('/moderation/{userId}/warn', [\App\Http\Controllers\Admin\ModerationController::class, 'warn']);
        Route::post('/moderation/{userId}/reinstate', [\App\Http\Controllers\Admin\ModerationController::class, 'reinstate']);
    });
});

Route::middleware(['auth:sanctum'])->prefix('driver')->group(function () {
    Route::get('/profile', [DriverProfileController::class, 'show']);
    Route::post('/profile', [DriverProfileController::class, 'store']);
    // Accepter le contrat : accessible même si le statut est 'pending'
    Route::post('/contract/accept', [DriverProfileController::class, 'acceptContract']);
    Route::get('/daily-tip', [\App\Http\Controllers\SettingController::class, 'getDailyTip']);
    Route::get('/notifications', [\App\Http\Controllers\Api\Driver\NotificationController::class, 'index']);
    // Status endpoint should be available to all authenticated drivers, not just approved ones
    Route::post('/status', [TripsController::class, 'updateDriverStatus']);
    // Vehicle update endpoint
    Route::post('/update-vehicle', [TripsController::class, 'updateVehicle']);
});

Route::middleware(['auth:sanctum', 'role:driver', 'driver.approved'])->prefix('driver')->group(function () {
    Route::get('/ping', fn() => response()->json(['ok' => true, 'area' => 'driver']));
    Route::post('/location', [TripsController::class, 'updateDriverLocation']);
    Route::get('/rides', [TripsController::class, 'driverRides']);
    Route::get('/rides/{id}', [TripsController::class, 'driverRideShow']);
    Route::get('/current-ride', [TripsController::class, 'driverCurrentRide']);
    Route::get('/stats', [TripsController::class, 'driverStats']);
    // Portefeuille chauffeur (même contrôleur que passager, basé sur user_id)
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions/today', [WalletController::class, 'todayTransactions']);
    Route::post('/wallet/withdraw', [\App\Http\Controllers\WithdrawController::class, 'store']);
    Route::get('/next-offer', [TripsController::class, 'driverNextOffer']);
    Route::post('/trips/{id}/accept', [TripsController::class, 'accept']);
    Route::post('/trips/{id}/decline', [TripsController::class, 'decline']);
    Route::post('/trips/{id}/arrived', [TripsController::class, 'arrived']);
    Route::post('/trips/{id}/start', [TripsController::class, 'start']);
    Route::post('/trips/{id}/complete', [TripsController::class, 'complete']);
    Route::post('/trips/{id}/cancel', [TripsController::class, 'cancelByDriver']);
    Route::post('/trips/{id}/start-stop', [TripsController::class, 'startStop']);
    Route::post('/trips/{id}/end-stop', [TripsController::class, 'endStop']);
});

Route::middleware(['auth:sanctum', 'role:passenger'])->prefix('passenger')->group(function () {
    Route::get('/ping', fn() => response()->json(['ok' => true, 'area' => 'passenger']));
    Route::get('/rides', [TripsController::class, 'passengerRides']);
    Route::get('/rides/current', [TripsController::class, 'currentPassengerRide']);
    Route::get('/rides/active-count', [TripsController::class, 'activeRidesCount']);
    Route::get('/rides/{id}', [TripsController::class, 'passengerRideShow']);
    Route::get('/rides/{id}/wait-assignment', [TripsController::class, 'passengerRideWaitAssignment']);
    Route::get('/addresses', [PassengerAddressController::class, 'index']);
    Route::post('/addresses', [PassengerAddressController::class, 'store']);
    Route::put('/addresses/{id}', [PassengerAddressController::class, 'update']);
    Route::delete('/addresses/{id}', [PassengerAddressController::class, 'destroy']);
    Route::post('/rides/{id}/cancel', [TripsController::class, 'cancelByPassenger']);
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions', [WalletController::class, 'todayTransactions']);
    Route::post('/wallet/topup', [WalletController::class, 'topup']);
    Route::post('/rides/{id}/pay', [WalletController::class, 'payRide']);
    Route::get('/rides/{id}/driver-location', [TripsController::class, 'passengerRideDriverLocation']);
    Route::post('/ratings', [RatingsController::class, 'store']);

    // Lignes TIC (stops + estimation tarifaire)
    Route::get('/stops', [PassengerLineController::class, 'stops']);
    Route::get('/lines', [PassengerLineController::class, 'lines']);
    Route::post('/lines/estimate', [PassengerLineController::class, 'estimate']);
});

Route::middleware(['auth:sanctum'])->prefix('trips')->group(function () {
    Route::post('/estimate', [TripsController::class, 'estimate']);
    Route::post('/create', [TripsController::class, 'create']);
    Route::post('/request', [TripsController::class, 'requestTicRide']);
});

// Analytics endpoint pour les apps mobiles
Route::middleware(['auth:sanctum'])->prefix('analytics')->group(function () {
    Route::post('/reconnection', [\App\Http\Controllers\Admin\AnalyticsController::class, 'trackReconnection']);
});

// Public geocoding proxy (throttled)
Route::prefix('geocoding')->middleware('throttle:300,1')->group(function () {
    Route::get('/search', [GeocodingController::class, 'search']);
    Route::get('/reverse', [GeocodingController::class, 'reverse']);
});

// Public voice search (throttled)
Route::prefix('voice')->middleware('throttle:60,1')->group(function () {
    Route::post('/search', [VoiceController::class, 'search']);
});

// Public routing estimate (throttled)
Route::prefix('routing')->middleware('throttle:300,1')->group(function () {
    Route::post('/estimate', [TripsController::class, 'estimateFromCoords']);
});


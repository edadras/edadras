<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\ClubController;
use App\Http\Controllers\Api\CoachController;
use App\Http\Controllers\Api\CrmController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\MemberAppController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\OnlinePaymentController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\TranslationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GymFlow AI API
|--------------------------------------------------------------------------
| Three surfaces share this file: the manager app, the member app and the
| Super Admin panel. The tenant is resolved before anything else runs.
*/

Route::prefix('v1')->group(function () {

    // ---------------------------------------------------------------- public
    Route::get('locales', [TranslationController::class, 'locales']);
    Route::get('translations/{locale}', [TranslationController::class, 'index']);
    Route::post('clubs/register', [AuthController::class, 'registerClub']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/member-login', [AuthController::class, 'memberLogin']);

    // The gateway redirects the payer here with no token of ours, so the club
    // comes from the URL and the payment from a token only we issued.
    Route::match(['get', 'post'], 'payments/callback/{tenant}/{token}', [OnlinePaymentController::class, 'callback'])
        ->name('payments.callback')
        ->withoutMiddleware('throttle:api');

    // ------------------------------------------------------------- signed in
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/password', [AuthController::class, 'updatePassword']);
        Route::post('auth/push-tokens', [AuthController::class, 'registerPushToken']);
        Route::post('translations', [TranslationController::class, 'store']);

        // ------------------------------------------------------- manager app
        Route::get('dashboard', [ReportController::class, 'dashboard']);

        Route::prefix('club')->group(function () {
            Route::get('/', [ClubController::class, 'show']);
            Route::put('/', [ClubController::class, 'update']);
            Route::post('logo', [ClubController::class, 'uploadLogo']);
            Route::get('settings', [ClubController::class, 'settings']);
            Route::put('settings', [ClubController::class, 'updateSettings']);
            Route::get('staff', [ClubController::class, 'staff']);
            Route::post('staff', [ClubController::class, 'storeStaff']);
            Route::put('staff/{user}', [ClubController::class, 'updateStaff']);
            Route::delete('staff/{user}', [ClubController::class, 'destroyStaff']);
            Route::get('roles', [ClubController::class, 'roles']);
            Route::post('roles', [ClubController::class, 'storeRole']);
            Route::put('roles/{role}', [ClubController::class, 'updateRole']);
            Route::get('audit-logs', [ClubController::class, 'auditLogs']);
        });

        // Members
        Route::get('members/{member}/qr', [MemberController::class, 'qrCode']);
        Route::post('members/{member}/qr', [MemberController::class, 'regenerateQrCode']);
        Route::post('members/{member}/photo', [MemberController::class, 'uploadPhoto']);
        Route::post('members/{member}/nfc', [MemberController::class, 'linkNfc']);
        Route::get('members/{member}/measurements', [ProgramController::class, 'measurements']);
        Route::post('members/{member}/measurements', [ProgramController::class, 'storeMeasurement']);
        Route::post('members/{member}/generate-workout', [ProgramController::class, 'generateWorkoutPlan']);
        Route::post('members/{member}/generate-nutrition', [ProgramController::class, 'generateNutritionPlan']);
        Route::get('members/{member}/wallet', [FinanceController::class, 'wallet']);
        Route::post('members/{member}/wallet', [FinanceController::class, 'topUpWallet']);
        Route::apiResource('members', MemberController::class);

        // Memberships and the price list
        Route::get('membership-plans', [MembershipController::class, 'plans']);
        Route::post('membership-plans', [MembershipController::class, 'storePlan']);
        Route::put('membership-plans/{plan}', [MembershipController::class, 'updatePlan']);
        Route::get('memberships', [MembershipController::class, 'index']);
        Route::post('memberships', [MembershipController::class, 'store']);
        Route::get('memberships/{membership}', [MembershipController::class, 'show']);
        Route::post('memberships/{membership}/renew', [MembershipController::class, 'renew']);
        Route::post('memberships/{membership}/freeze', [MembershipController::class, 'freeze']);
        Route::post('memberships/{membership}/unfreeze', [MembershipController::class, 'unfreeze']);
        Route::post('memberships/{membership}/cancel', [MembershipController::class, 'cancel']);
        Route::post('memberships/{membership}/sessions', [MembershipController::class, 'adjustSessions']);

        // The gate
        Route::prefix('attendance')->group(function () {
            Route::get('/', [AttendanceController::class, 'index']);
            Route::get('inside', [AttendanceController::class, 'inside']);
            Route::post('preview', [AttendanceController::class, 'preview']);
            Route::post('check-in', [AttendanceController::class, 'checkIn']);
            Route::post('check-out', [AttendanceController::class, 'checkOut']);
            Route::post('manual', [AttendanceController::class, 'storeManual']);
        });

        // Coaches
        Route::get('coaches-performance', [CoachController::class, 'performance']);
        Route::post('coaches/{coach}/salary', [CoachController::class, 'paySalary']);
        Route::apiResource('coaches', CoachController::class);

        // Classes, pool sessions and bookings
        Route::get('classes', [ClassController::class, 'index']);
        Route::post('classes', [ClassController::class, 'store']);
        Route::put('classes/{class}', [ClassController::class, 'update']);
        Route::delete('classes/{class}', [ClassController::class, 'destroy']);
        Route::get('class-sessions', [ClassController::class, 'sessions']);
        Route::post('class-sessions', [ClassController::class, 'storeSession']);
        Route::post('class-sessions/schedule', [ClassController::class, 'scheduleSessions']);
        Route::post('class-sessions/{session}/cancel', [ClassController::class, 'cancelSession']);
        Route::get('class-sessions/{session}/bookings', [ClassController::class, 'sessionBookings']);
        Route::post('class-sessions/{session}/book', [ClassController::class, 'book']);
        Route::post('bookings/{booking}/cancel', [ClassController::class, 'cancelBooking']);
        Route::post('bookings/{booking}/attendance', [ClassController::class, 'markAttendance']);

        // Programs and body metrics
        Route::get('workout-plans', [ProgramController::class, 'workoutPlans']);
        Route::post('workout-plans', [ProgramController::class, 'storeWorkoutPlan']);
        Route::get('workout-plans/{plan}', [ProgramController::class, 'showWorkoutPlan']);
        Route::delete('workout-plans/{plan}', [ProgramController::class, 'deleteWorkoutPlan']);
        Route::get('nutrition-plans', [ProgramController::class, 'nutritionPlans']);
        Route::post('nutrition-plans', [ProgramController::class, 'storeNutritionPlan']);
        Route::get('nutrition-plans/{plan}', [ProgramController::class, 'showNutritionPlan']);
        Route::delete('measurements/{measurement}', [ProgramController::class, 'deleteMeasurement']);

        // Money
        Route::prefix('finance')->group(function () {
            Route::get('transactions', [FinanceController::class, 'transactions']);
            Route::post('expenses', [FinanceController::class, 'storeExpense']);
            Route::post('incomes', [FinanceController::class, 'storeIncome']);
            Route::get('register', [FinanceController::class, 'dailyRegister']);
        });
        Route::get('invoices', [FinanceController::class, 'invoices']);
        Route::post('invoices', [FinanceController::class, 'storeInvoice']);
        Route::get('invoices/{invoice}', [FinanceController::class, 'showInvoice']);
        Route::get('invoices/{invoice}/pdf', [FinanceController::class, 'invoicePdf']);
        Route::post('invoices/{invoice}/pay', [FinanceController::class, 'payInvoice']);

        // Shop and stock
        Route::post('shop/sell', [ShopController::class, 'sell']);
        Route::post('products/{product}/stock', [ShopController::class, 'receiveStock']);
        Route::post('products/{product}/adjust', [ShopController::class, 'adjustStock']);
        Route::get('products/{product}/movements', [ShopController::class, 'movements']);
        Route::apiResource('products', ShopController::class)->except('show');

        // Reports
        Route::get('reports', [ReportController::class, 'catalogue']);
        Route::get('reports/{key}/export', [ReportController::class, 'export']);
        Route::get('reports/{key}', [ReportController::class, 'show']);

        // CRM and chat
        Route::get('campaigns', [CrmController::class, 'campaigns']);
        Route::post('campaigns', [CrmController::class, 'storeCampaign']);
        Route::post('campaigns/audience', [CrmController::class, 'previewAudience']);
        Route::post('campaigns/preview', [CrmController::class, 'previewCampaign']);
        Route::get('campaigns/channels', [CrmController::class, 'channelStatus']);
        Route::post('campaigns/{campaign}/send', [CrmController::class, 'sendCampaign']);
        Route::get('conversations', [CrmController::class, 'conversations']);
        Route::post('conversations', [CrmController::class, 'startConversation']);
        Route::get('conversations/{conversation}/messages', [CrmController::class, 'messages']);
        Route::post('conversations/{conversation}/messages', [CrmController::class, 'sendMessage']);

        // AI module
        Route::prefix('ai')->group(function () {
            Route::get('overview', [AiController::class, 'overview']);
            Route::get('churn-risk', [AiController::class, 'churnRisk']);
            Route::get('forecast', [AiController::class, 'forecast']);
            Route::get('insights', [AiController::class, 'insights']);
            Route::post('refresh', [AiController::class, 'refresh']);
            Route::post('insights/{insight}/dismiss', [AiController::class, 'dismiss']);
            Route::post('chat', [AiController::class, 'chat']);
        });

        // -------------------------------------------------------- member app
        Route::prefix('me')->group(function () {
            Route::get('dashboard', [MemberAppController::class, 'dashboard']);
            Route::get('qr', [MemberAppController::class, 'qrCode']);
            Route::get('attendance', [MemberAppController::class, 'attendanceHistory']);
            Route::get('payments', [MemberAppController::class, 'payments']);
            Route::get('wallet', [MemberAppController::class, 'wallet']);
            Route::get('schedule', [MemberAppController::class, 'schedule']);
            Route::get('bookings', [MemberAppController::class, 'bookings']);
            Route::post('sessions/{session}/book', [MemberAppController::class, 'book']);
            Route::post('bookings/{booking}/cancel', [MemberAppController::class, 'cancelBooking']);
            Route::get('programs', [MemberAppController::class, 'programs']);
            Route::get('measurements', [MemberAppController::class, 'measurements']);
            Route::put('profile', [MemberAppController::class, 'updateProfile']);
            Route::get('notifications', [MemberAppController::class, 'notifications']);
            Route::get('payment-gateway', [OnlinePaymentController::class, 'gateway']);
            Route::post('payments/start', [OnlinePaymentController::class, 'start']);
            Route::get('payments/{payment}/status', [OnlinePaymentController::class, 'status']);
            Route::post('notifications/read', [MemberAppController::class, 'markNotificationsRead']);
        });

        // ------------------------------------------------------- super admin
        Route::prefix('platform')->middleware('super-admin')->group(function () {
            Route::get('dashboard', [PlatformController::class, 'dashboard']);
            Route::get('tenants', [PlatformController::class, 'tenants']);
            Route::get('tenants/{tenant}', [PlatformController::class, 'showTenant']);
            Route::put('tenants/{tenant}', [PlatformController::class, 'updateTenant']);
            Route::post('tenants/{tenant}/subscription', [PlatformController::class, 'subscribeTenant']);
            Route::get('plans', [PlatformController::class, 'plans']);
            Route::post('plans', [PlatformController::class, 'storePlan']);
            Route::put('plans/{plan}', [PlatformController::class, 'updatePlan']);
            Route::get('audit-logs', [PlatformController::class, 'auditLogs']);
        });
    });
});

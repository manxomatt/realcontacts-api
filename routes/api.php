<?php

use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\OtpController;
use App\Http\Controllers\Api\Block\CallBlockerController;
use App\Http\Controllers\Api\Business\BusinessProfileController;
use App\Http\Controllers\Api\Phone\BatchLookupController;
use App\Http\Controllers\Api\Phone\PhoneLookupController;
use App\Http\Controllers\Api\Spam\SpamModerationController;
use App\Http\Controllers\Api\Spam\SpamReportController;
use App\Http\Controllers\Api\User\UserController;
use Illuminate\Support\Facades\Route;

// ╔════════════════════════════════════════════════════════════════════════════╗
// ║                   PUBLIC ENDPOINTS — Tidak memerlukan auth                  ║
// ╚════════════════════════════════════════════════════════════════════════════╝

// ─── Autentikasi & OTP ──────────────────────────────────────────────────────
Route::prefix('auth')->name('auth.')->group(function (): void {
    // Register: POST /api/auth/register
    Route::post('register', [AuthController::class, 'register'])
        ->name('register');

    // OTP Request: POST /api/auth/otp/request
    Route::prefix('otp')->name('otp.')->group(function (): void {
        Route::post('request', [OtpController::class, 'request'])
            ->name('request');
        Route::post('resend', [OtpController::class, 'resend'])
            ->name('resend');
    });

    // Verify & Login: POST /api/auth/verify-otp
    Route::post('verify-otp', [AuthController::class, 'verifyAndLogin'])
        ->name('verify-otp');
});

// ╔════════════════════════════════════════════════════════════════════════════╗
// ║              PROTECTED ENDPOINTS — Memerlukan Sanctum token                 ║
// ╚════════════════════════════════════════════════════════════════════════════╝

Route::middleware('auth:sanctum')->group(function (): void {

    // ─── Auth & Profile ────────────────────────────────────────────────────
    Route::prefix('auth')->name('auth.')->group(function (): void {
        // Logout: POST /api/auth/logout
        Route::post('logout', [AuthController::class, 'logout'])
            ->name('logout');
        
        // Get profile: GET /api/auth/me
        Route::get('me', [AuthController::class, 'me'])
            ->name('me');
    });

    // ─── User Management ───────────────────────────────────────────────────
    Route::prefix('user')->name('user.')->group(function (): void {
        // Get profile: GET /api/user/profile
        Route::get('profile', [UserController::class, 'profile'])
            ->name('profile');

        // Update profile: PATCH /api/user/profile
        Route::patch('profile', [UserController::class, 'update'])
            ->name('update');

        // Get number health: GET /api/user/number-health
        Route::get('number-health', [UserController::class, 'numberHealth'])
            ->name('number-health');

        // Get contributions: GET /api/user/contributions
        Route::get('contributions', [UserController::class, 'contributions'])
            ->name('contributions');

        // Get leaderboard: GET /api/user/leaderboard
        Route::get('leaderboard', [UserController::class, 'leaderboard'])
            ->name('leaderboard');
    });

    // ─── Phone Lookup ──────────────────────────────────────────────────────
    Route::prefix('phone')->name('phone.')->group(function (): void {
        // Single lookup: GET /api/phone/{number}
        Route::get('{number}', [PhoneLookupController::class, 'show'])
            ->name('show');

        // Search: GET /api/phone/search
        Route::get('search', [PhoneLookupController::class, 'search'])
            ->name('search');

        // Recent lookups: GET /api/phone/recent
        Route::get('recent', [PhoneLookupController::class, 'recentLookups'])
            ->name('recent');
    });

    // ─── Batch Lookup (Premium) ───────────────────────────────────────────
    Route::prefix('phone/batch')->name('phone.batch.')->group(function (): void {
        // Batch lookup: POST /api/phone/batch
        Route::post('/', [BatchLookupController::class, 'lookup'])
            ->name('lookup');
    });

    // ─── Spam Reports ─────────────────────────────────────────────────────
    Route::prefix('spam-reports')->name('spam-reports.')->group(function (): void {
        // List reports for phone: GET /api/spam-reports/{phone}
        Route::get('{phoneNumber}', [SpamReportController::class, 'index'])
            ->name('index');

        // Submit report: POST /api/spam-reports
        Route::post('/', [SpamReportController::class, 'store'])
            ->name('store');

        // Vote on report: POST /api/spam-reports/{report}/vote
        Route::post('{report}/vote', [SpamReportController::class, 'vote'])
            ->name('vote');

        // My reports: GET /api/spam-reports/me/reports
        Route::get('me/reports', [SpamReportController::class, 'myReports'])
            ->name('my-reports');
    });

    // ─── Call Blocker (Blocklist) ─────────────────────────────────────────
    Route::prefix('blocked-numbers')->name('blocked-numbers.')->group(function (): void {
        // Get blocklist: GET /api/blocked-numbers
        Route::get('/', [CallBlockerController::class, 'index'])
            ->name('index');

        // Add to blocklist: POST /api/blocked-numbers
        Route::post('/', [CallBlockerController::class, 'store'])
            ->name('store');

        // Remove from blocklist: DELETE /api/blocked-numbers/{number}
        Route::delete('{number}', [CallBlockerController::class, 'destroy'])
            ->name('destroy');

        // Check if blocked: GET /api/blocked-numbers/{number}/check
        Route::get('{number}/check', [CallBlockerController::class, 'check'])
            ->name('check');

        // Bulk import blocklist: POST /api/blocked-numbers/import
        Route::post('import', [CallBlockerController::class, 'import'])
            ->name('import');
    });

    // ─── Business Profiles ────────────────────────────────────────────────
    Route::prefix('business-profiles')->name('business-profiles.')->group(function (): void {
        // Get profile: GET /api/business-profiles/{number}
        Route::get('{number}', [BusinessProfileController::class, 'show'])
            ->name('show');

        // Claim profile: POST /api/business-profiles/{number}/claim
        Route::post('{number}/claim', [BusinessProfileController::class, 'claim'])
            ->name('claim');

        // Update profile: PATCH /api/business-profiles/{profile}
        Route::patch('{profile}', [BusinessProfileController::class, 'update'])
            ->name('update');

        // Rate business: POST /api/business-profiles/{number}/rate
        Route::post('{number}/rate', [BusinessProfileController::class, 'rate'])
            ->name('rate');
    });
});

// ╔════════════════════════════════════════════════════════════════════════════╗
// ║                    ADMIN ENDPOINTS — Admin only                             ║
// ╚════════════════════════════════════════════════════════════════════════════╝

Route::middleware(['auth:sanctum', 'admin'])->group(function (): void {

    // ─── Spam Moderation ────────────────────────────────────────────────────
    Route::prefix('spam/moderation')->name('spam.moderation.')->group(function (): void {
        // Get pending reports: GET /api/spam/moderation/pending
        Route::get('pending', [SpamModerationController::class, 'pending'])
            ->name('pending');

        // Approve report: PATCH /api/spam/moderation/{report}/approve
        Route::patch('{report}/approve', [SpamModerationController::class, 'approve'])
            ->name('approve');

        // Reject report: PATCH /api/spam/moderation/{report}/reject
        Route::patch('{report}/reject', [SpamModerationController::class, 'reject'])
            ->name('reject');

        // Moderation stats: GET /api/spam/moderation/stats
        Route::get('stats', [SpamModerationController::class, 'stats'])
            ->name('stats');
    });

    // ─── Admin Dashboard ────────────────────────────────────────────────────
    Route::prefix('admin/dashboard')->name('admin.dashboard.')->group(function (): void {
        // Overall stats: GET /api/admin/dashboard/stats
        Route::get('stats', [DashboardController::class, 'stats'])
            ->name('stats');

        // Real-time activity: GET /api/admin/dashboard/activity
        Route::get('activity', [DashboardController::class, 'activity'])
            ->name('activity');

        // Top spam numbers: GET /api/admin/dashboard/top-spam-numbers
        Route::get('top-spam-numbers', [DashboardController::class, 'topSpamNumbers'])
            ->name('top-spam');

        // Reporter leaderboard: GET /api/admin/dashboard/reporter-leaderboard
        Route::get('reporter-leaderboard', [DashboardController::class, 'reporterLeaderboard'])
            ->name('leaderboard');
    });
});

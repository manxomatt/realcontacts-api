<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlockedNumberController;
use App\Http\Controllers\Api\BusinessProfileController;
use App\Http\Controllers\Api\PhoneLookupController;
use App\Http\Controllers\Api\SpamReportController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// ─── Public — Autentikasi ──────────────────────────────────────────────────────
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register',   [AuthController::class, 'register'])->name('register');
    Route::post('verify-otp', [AuthController::class, 'verifyOtp'])->name('verify-otp');
});

// ─── Protected — Memerlukan Sanctum token ─────────────────────────────────────
Route::middleware('auth:sanctum')->group(function (): void {

    // Auth
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // Profil user
    Route::prefix('user')->name('user.')->group(function (): void {
        Route::get('profile',   [UserController::class, 'show'])->name('profile.show');
        Route::patch('profile', [UserController::class, 'update'])->name('profile.update');
    });

    // Phone lookup
    Route::prefix('phone')->name('phone.')->group(function (): void {
        Route::get('{number}',   [PhoneLookupController::class, 'show'])->name('lookup');
        Route::post('batch',     [PhoneLookupController::class, 'batch'])->name('batch');
    });

    // Spam reports
    Route::prefix('spam-reports')->name('spam-reports.')->group(function (): void {
        Route::get('/',                        [SpamReportController::class, 'index'])->name('index');
        Route::post('/',                       [SpamReportController::class, 'store'])->name('store');
        Route::patch('{spamReport}',           [SpamReportController::class, 'update'])->name('update');
    });

    // Blocked numbers
    Route::prefix('blocked-numbers')->name('blocked-numbers.')->group(function (): void {
        Route::get('/',          [BlockedNumberController::class, 'index'])->name('index');
        Route::post('/',         [BlockedNumberController::class, 'store'])->name('store');
        Route::delete('{id}',    [BlockedNumberController::class, 'destroy'])->name('destroy');
    });

    // Business profiles
    Route::prefix('business-profiles')->name('business-profiles.')->group(function (): void {
        Route::get('{businessProfile}',        [BusinessProfileController::class, 'show'])->name('show');
        Route::patch('{businessProfile}',      [BusinessProfileController::class, 'update'])->name('update');
    });
});

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SmsProviderInterface;
use App\Models\ContactTag;
use App\Models\UserContact;
use App\Policies\ContactTagPolicy;
use App\Policies\UserContactPolicy;
use App\Services\Sms\NullSmsProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * AppServiceProvider
 *
 * Register dan bootstrap aplikasi services, policies, dan bindings.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Tempat untuk register interface ke concrete implementation,
     * configure services, dll.
     */
    public function register(): void
    {
        // ─── Register SMS Provider ────────────────────────────────────────
        // Kondisional berdasarkan OTP_SMS_ENABLED di .env
        // - true: Gunakan SMS provider yang sudah dikonfigurasi (future: Twilio, Vonage, dll)
        // - false: Gunakan NullSmsProvider (no-op, untuk dev/testing)
        if (! config('otp.sms_enabled')) {
            // SMS disabled — gunakan NullSmsProvider
            // OTP tetap di-generate dan disimpan, cukup tidak dikirim SMS
            $this->app->bind(SmsProviderInterface::class, NullSmsProvider::class);
        } else {
            // SMS enabled — TODO: Bind ke provider yang sebenarnya
            // Contoh: $this->app->bind(SmsProviderInterface::class, TwilioSmsProvider::class);
            // Untuk sekarang, fallback ke NullSmsProvider sampai ada provider nyata
            $this->app->bind(SmsProviderInterface::class, NullSmsProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * Tempat untuk register event listeners, policies, middelware, dll.
     */
    public function boot(): void
    {
        // ─── Register Policies ────────────────────────────────────────────
        Gate::policy(UserContact::class, UserContactPolicy::class);
        Gate::policy(ContactTag::class, ContactTagPolicy::class);
    }
}

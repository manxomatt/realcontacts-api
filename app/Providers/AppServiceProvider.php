<?php

namespace App\Providers;

use App\Models\ContactTag;
use App\Models\UserContact;
use App\Policies\ContactTagPolicy;
use App\Policies\UserContactPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ─── Register Policies ────────────────────────────────────────────
        Gate::policy(UserContact::class, UserContactPolicy::class);
        Gate::policy(ContactTag::class, ContactTagPolicy::class);
    }
}

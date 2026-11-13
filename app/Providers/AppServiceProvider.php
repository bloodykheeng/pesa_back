<?php

namespace App\Providers;

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
        // The staff bell: new orders, package bookings and sign-ups from the app.
        \App\Models\Order::observe(\App\Observers\StaffAlertObserver::class);
        \App\Models\Package::observe(\App\Observers\StaffAlertObserver::class);
        \App\Models\User::observe(\App\Observers\StaffAlertObserver::class);
    }
}

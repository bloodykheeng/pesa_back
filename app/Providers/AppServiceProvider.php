<?php

namespace App\Providers;

use App\Models;
use App\Observers\AuditObserver;
use App\Observers\SignInObserver;
use App\Observers\StaffAlertObserver;
use App\Support\Audit\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /** Models whose creates, edits and deletes go into the audit trail (each has a snapshotSchema()). */
    public const AUDITED = [
        Models\Order::class, Models\Package::class, Models\Payment::class, Models\PackagePayment::class,
        Models\Product::class, Models\User::class,
        Models\ProductCategory::class, Models\CategoryBrand::class, Models\ProductType::class, Models\InventoryType::class,
        Models\ElectronicCategory::class, Models\ElectronicBrand::class, Models\ElectronicType::class,
        Models\ExploreCategory::class, Models\ExploreCategoryBlog::class, Models\Ad::class, Models\Faq::class,
        Models\Referal::class, Models\AdminNotification::class,
    ];

    public function register(): void
    {
        // One audit recorder per request: it gathers the request's entries and writes them at the end.
        $this->app->singleton(Audit::class);
    }

    public function boot(): void
    {
        // The staff bell: new orders, package bookings and sign-ups from the app.
        Models\Order::observe(StaffAlertObserver::class);
        Models\Package::observe(StaffAlertObserver::class);
        Models\User::observe(StaffAlertObserver::class);

        // The audit trail (docs/audit-trail.md).
        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }
        PersonalAccessToken::observe(SignInObserver::class);
        Event::listen(function (Failed $event) {
            Audit::event('sign_in_failed', $event->user, 'Sign-in failed in the app', [
                'login' => $event->credentials['email'] ?? $event->credentials['phone'] ?? null,
            ]);
        });
    }
}

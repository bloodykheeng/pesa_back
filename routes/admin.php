<?php

/*
|--------------------------------------------------------------------------
| Admin dashboard API: /api/admin/*
|--------------------------------------------------------------------------
| Used only by the Next.js admin (pesa_nextjs_admin). Paged, filtered and sorted lists
| (?page, per_page, sort, filter[...]), View endpoints, permission checks. Never
| called by the mobile app, so these responses can change with the dashboard.
| Updates are POST so forms can carry a photo (multipart).
*/

use App\Admin\Catalog;
use App\Http\Controllers\Admin\AuditTrailController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InboxController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OptionsController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function () {

    // Sign-in (no token yet)
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot');

    Route::middleware(['auth:sanctum', 'role:Admin|Editor'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile');
        Route::post('auth/password', [AuthController::class, 'changePassword'])->name('auth.password');

        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('options', OptionsController::class)->name('options');

        // The bell
        Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::post('inbox/read-all', [InboxController::class, 'readAll'])->name('inbox.read-all');
        Route::post('inbox/{id}/read', [InboxController::class, 'read'])->name('inbox.read');

        // Orders and packages
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::post('orders/bulk-delete', [OrderController::class, 'destroyMany'])->name('orders.bulk-delete');
        Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('orders/{id}', [OrderController::class, 'show'])->whereNumber('id')->name('orders.show');
        Route::post('orders/{id}', [OrderController::class, 'update'])->whereNumber('id')->name('orders.update');
        Route::post('orders/{id}/payments', [OrderController::class, 'recordPayment'])->whereNumber('id')->name('orders.payments');

        Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
        Route::post('packages/bulk-delete', [PackageController::class, 'destroyMany'])->name('packages.bulk-delete');
        Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
        Route::get('packages/{id}', [PackageController::class, 'show'])->whereNumber('id')->name('packages.show');
        Route::post('packages/{id}', [PackageController::class, 'update'])->whereNumber('id')->name('packages.update');
        Route::post('packages/{id}/payments', [PackageController::class, 'recordPayment'])->whereNumber('id')->name('packages.payments');

        Route::get('payments', [PaymentController::class, 'orderPayments'])->name('payments.index');
        Route::get('payments/{id}', [PaymentController::class, 'showOrderPayment'])->whereNumber('id')->name('payments.show');
        Route::get('package-payments', [PaymentController::class, 'packagePayments'])->name('package-payments.index');
        Route::get('package-payments/{id}', [PaymentController::class, 'showPackagePayment'])->whereNumber('id')->name('package-payments.show');

        // Products
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::post('products/bulk-delete', [ProductController::class, 'destroyMany'])->name('products.bulk-delete');
        Route::get('products/{id}', [ProductController::class, 'show'])->whereNumber('id')->name('products.show');
        Route::post('products/{id}', [ProductController::class, 'update'])->whereNumber('id')->name('products.update');

        // The simple tables (App\Admin\Catalog)
        Route::prefix('{resource}')->whereIn('resource', array_keys(Catalog::all()))->name('catalog.')->group(function () {
            Route::get('/', [CatalogController::class, 'index'])->name('index');
            Route::post('/', [CatalogController::class, 'store'])->name('store');
            Route::post('bulk-delete', [CatalogController::class, 'destroyMany'])->name('bulk-delete');
            Route::get('{id}', [CatalogController::class, 'show'])->whereNumber('id')->name('show');
            Route::post('{id}', [CatalogController::class, 'update'])->whereNumber('id')->name('update');
        });

        // One account: Editors too, for a customer opened over an order or payment (the controller keeps staff
        // accounts to Admins).
        // Customers to pick when staff enter an order or a package (Editors too): name, phone, email only.
        Route::get('customers', [UserController::class, 'customers'])->name('customers.index');
        Route::get('users/{id}', [UserController::class, 'show'])->whereNumber('id')->name('users.show');

        // Admins only: accounts, roles, messages to customers, the audit trail
        Route::middleware('role:Admin')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::post('users/bulk-delete', [UserController::class, 'destroyMany'])->name('users.bulk-delete');
            Route::post('users/{id}', [UserController::class, 'update'])->whereNumber('id')->name('users.update');

            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('roles/{id}', [RoleController::class, 'update'])->whereNumber('id')->name('roles.update');

            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications', [NotificationController::class, 'store'])->name('notifications.store');
            Route::post('notifications/preview', [NotificationController::class, 'preview'])->name('notifications.preview');
            Route::get('notifications/{id}', [NotificationController::class, 'show'])->whereNumber('id')->name('notifications.show');
            Route::get('notifications/{id}/recipients', [NotificationController::class, 'recipients'])->whereNumber('id')->name('notifications.recipients');

            Route::get('audit-trail', [AuditTrailController::class, 'index'])->name('audit-trail.index');
            Route::get('audit-trail/{id}', [AuditTrailController::class, 'show'])->whereNumber('id')->name('audit-trail.show');
        });
    });
});

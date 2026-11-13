<?php

use App\Http\Controllers\API\AdsController;
use App\Http\Controllers\API\CategoryBrandController;
use App\Http\Controllers\API\dashboard\BarChartsController;
use App\Http\Controllers\API\dashboard\StatisticsCardsController;
use App\Http\Controllers\API\ElectronicBrandController;
use App\Http\Controllers\API\ElectronicCategoryController;
use App\Http\Controllers\API\ElectronicTypeController;
use App\Http\Controllers\API\ExploreCategoryBlogController;
use App\Http\Controllers\API\ExploreCategoryController;
use App\Http\Controllers\API\FaqController;
use App\Http\Controllers\API\InventoryTypeController;
use App\Http\Controllers\API\MessageController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\OrdersExcellExportController;
use App\Http\Controllers\API\PackageController;
use App\Http\Controllers\API\PackagePaymentController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\ProductCategoryController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\ProductTypeController;
use App\Http\Controllers\API\ReferalController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\UserPermissionsController;
use App\Http\Controllers\API\UserRolesController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Who may call what
|--------------------------------------------------------------------------
| The Flutter app (github.com/MarkRoi/elevate-pesa) has the API URL built in and
| calls the routes marked "app" below. Their URIs, methods and responses must not
| change: docs/plan-admin-nextjs.md in the workspace lists them.
|
| - public:          no token
| - app:             any signed-in user (customers through the app, staff)
| - own record:      a customer only for their own id, staff for anyone
| - staff:           Admin or Editor only (the admin dashboard)
*/

// ---------------------------------------------------------------- public
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/app-login', [AuthController::class, 'applogin'])->name('applogin');
Route::post('/third-party-login-auth', [AuthController::class, 'thirdPartyLoginAuthentication'])->name('thirdPartyLoginAuthentication');
Route::post('/third-party-register-auth', [AuthController::class, 'thirdPartyRegisterAuthentication'])->name('thirdPartyRegisterAuthentication');

Route::post('forgot-password', [PasswordResetController::class, 'forgetPassword']);
Route::get('/reset-password', [PasswordResetController::class, 'handleresetPasswordLoad']);
Route::post('/reset-password', [PasswordResetController::class, 'handlestoringNewPassword']);

Route::get('/app-product-categories', [ProductCategoryController::class, 'app_index']);
Route::get('/app-category-brands', [CategoryBrandController::class, 'app_index']);
Route::get('/app-product-types', [ProductTypeController::class, 'app_index']);
Route::get('/app-products', [ProductController::class, 'app_index']);
Route::resource('app-explore-categories', ExploreCategoryController::class)->only(['index']);
Route::resource('app-explore-category-blogs', ExploreCategoryBlogController::class)->only(['index']);

Route::middleware('auth:sanctum')->group(function () {

    // ------------------------------------------------------------ app
    Route::post('/check-auth-token', [AuthController::class, 'checkAppLoginStatus']);
    Route::get('get-logged-in-user', [AuthController::class, 'checkLoginStatus']);
    Route::post('/save-token', [UserController::class, 'SaveToken']);
    Route::post('/profile-photo', [UserController::class, 'update_profile_photo']);

    Route::get('app-get-ads', [AdsController::class, 'get_ads']);
    Route::get('app-get-electronic-categories', [ElectronicCategoryController::class, 'indexForApp']);
    Route::get('app-get-electronic-brands', [ElectronicBrandController::class, 'indexForApp']);
    Route::get('app-get-electronic-types', [ElectronicTypeController::class, 'indexForApp']);
    Route::get('get-faqs', [FaqController::class, 'get_faqs']);
    Route::resource('products', ProductController::class)->only(['index', 'show']);

    Route::resource('orders', OrderController::class)->only(['store']);
    Route::get('my-orders', [OrderController::class, 'get_orders']);
    Route::post('/confirm-receipt/{id}', [OrderController::class, 'confirmReceipt']);
    Route::post('/cancel-order/{id}', [OrderController::class, 'cancelOrder']);
    Route::get('/calculate-overall-balance', [OrderController::class, 'calculateOverallBalance']);
    Route::get('my-payments', [PaymentController::class, 'get_payments']);

    Route::resource('packages', PackageController::class)->only(['store']);
    Route::get('my-packages', [PackageController::class, 'myPackages']);
    Route::post('/confirm-package-receipt/{id}', [PackageController::class, 'confirmPackageReceipt']);
    Route::post('/cancel-package-order/{id}', [PackageController::class, 'cancelPackageOrder']);
    Route::get('my-package-payments', [PackagePaymentController::class, 'get_package_payments']);

    Route::resource('referals', ReferalController::class)->only(['store']);

    Route::post('/messages', [MessageController::class, 'sendMessage']);
    Route::get('/messages', [MessageController::class, 'getMessages']);
    Route::patch('/messages/{id}/read', [MessageController::class, 'markAsRead']);
    Route::delete('/messages/{id}', [MessageController::class, 'deleteMessage']);

    // ------------------------------------------------------------ own record
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('self.or.staff:user')->name('users.show');
    Route::post('/profile-update/{id}', [UserController::class, 'profile_update'])->middleware('self.or.staff:id');
    Route::post('/change-password/{id}', [UserController::class, 'resetPassword'])->middleware('self.or.staff:id');

    // ------------------------------------------------------------ staff (old React admin)
    Route::middleware('role:Admin|Editor')->group(function () {
        Route::get('transaction-statistics', [StatisticsCardsController::class, 'getTransactionStatistics']);
        Route::get('order-statistics', [StatisticsCardsController::class, 'getOrderStatistics']);
        Route::get('package-statistics', [StatisticsCardsController::class, 'getPackageStatistics']);
        Route::get('customer-statistics', [StatisticsCardsController::class, 'getCustomerStatistics']);
        Route::get('product-barchat-stats', [BarChartsController::class, 'getProductStats']);
        Route::get('customer-barchart-stats', [BarChartsController::class, 'getCustomerStats']);
        Route::post('orders-excel-exports', [OrdersExcellExportController::class, 'exportOrdersData']);

        Route::resource('ads', AdsController::class);
        Route::apiResource('inventory-types', InventoryTypeController::class);
        Route::apiResource('electronic-categories', ElectronicCategoryController::class);
        Route::apiResource('electronic-brands', ElectronicBrandController::class);
        Route::apiResource('electronic-types', ElectronicTypeController::class);
        Route::post('send-notification', [NotificationController::class, 'sendNotification']);
        Route::resource('faqs', FaqController::class);

        Route::resource('product-categories', ProductCategoryController::class);
        Route::resource('category-brands', CategoryBrandController::class);
        Route::resource('product-types', ProductTypeController::class);
        Route::resource('products', ProductController::class)->except(['index', 'show']);
        Route::resource('explore-categories', ExploreCategoryController::class);
        Route::resource('explore-category-blogs', ExploreCategoryBlogController::class);

        Route::resource('packages', PackageController::class)->except(['store']);
        Route::resource('package-payments', PackagePaymentController::class);

        Route::resource('users', UserController::class)->except(['show']);

        Route::apiResource('orders', OrderController::class)->except(['store']);
        Route::get('/customer-orders-with-balance', [OrderController::class, 'showCustomersOrdersWithBalance']);
        Route::get('/orders-with-balance', [OrderController::class, 'showOrdersWithBalance']);
        Route::resource('payments', PaymentController::class);
        Route::post('/orders/{orderId}/record-payment', [PaymentController::class, 'recordPayment']);

        Route::resource('referals', ReferalController::class)->except(['store']);

        Route::get('/roles', [UserRolesController::class, 'getAssignableRoles']);
        Route::get('roles-with-modified-permissions', [UserRolesController::class, 'getRolesWithModifiedPermissions']);
        Route::post('sync-permissions-to-role', [UserRolesController::class, 'syncPermissionsToRole']);
        Route::resource('users-roles', UserRolesController::class);
        Route::post('users-roles-addPermissionsToRole', [UserRolesController::class, 'addPermissionsToRole']);
        Route::post('users-roles-deletePermissionFromRole', [UserRolesController::class, 'deletePermissionFromRole']);
        Route::resource('users-permissions', UserPermissionsController::class);
        Route::get('users-permissions-permissionNotInCurrentRole/{id}', [UserPermissionsController::class, 'permissionNotInCurrentRole']);
    });
});

require __DIR__ . '/admin.php';

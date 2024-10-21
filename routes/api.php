<?php

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
use App\Http\Controllers\API\PackageController;
use App\Http\Controllers\API\PackagePaymentController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\ProductCategoryController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\ProductTypeController;
use App\Http\Controllers\API\PushNotificationTestController;
use App\Http\Controllers\API\ReferalController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\UserPermissionsController;
use App\Http\Controllers\API\UserRolesController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailTestController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

//============== cors handler ================================
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
// header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token, Authorization, Accept,charset,boundary,Content-Length');

//====================== testing ========================
Route::get('testing', function () {
    return response()->json(['message' => 'testing indeed']);
});

/// Public Routes
Route::post('/register', [AuthController::class, 'register']);
// Route::post('/login', [AuthController::class, 'login']);

//check if user is still logged in
// Route::get('/user', [AuthController::class, 'checkLoginStatus']);
// Route::middleware('auth:sanctum')->get('/user', [AuthController::class, 'checkLoginStatus']);

Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/app-login', [AuthController::class, 'applogin'])->name('applogin');

Route::post('/third-party-login-auth', [AuthController::class, 'thirdPartyLoginAuthentication'])->name('thirdPartyLoginAuthentication');
Route::post('/third-party-register-auth', [AuthController::class, 'thirdPartyRegisterAuthentication'])->name('thirdPartyRegisterAuthentication');

Route::post('forgot-password', [PasswordResetController::class, 'forgetPassword']);
Route::get('/reset-password', [PasswordResetController::class, 'handleresetPasswordLoad']);
Route::post('/reset-password', [PasswordResetController::class, 'handlestoringNewPassword']);

//==================  product routes ============================
Route::resource('app-product-categories', ProductCategoryController::class)->only(['index']);
Route::resource('app-category-brands', CategoryBrandController::class)->only(['index', 'show']);
Route::resource('app-product-types', ProductTypeController::class)->only(['index']);
Route::resource('app-products', ProductController::class)->only(['index']);

// =============  explore section routes =============
Route::resource('app-explore-categories', ExploreCategoryController::class)->only(['index']);
Route::resource('app-explore-category-blogs', ExploreCategoryBlogController::class)->only(['index']);

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

//========== email testing ====
Route::post('test-email', [EmailTestController::class, 'testEmail']);

Route::post('/test-notification', [PushNotificationTestController::class, 'sendPushNotification']);

Route::get('transaction-statistics', [StatisticsCardsController::class, 'getTransactionStatistics']);

//=============================== private routes ==================================
Route::group(
    ['middleware' => ['auth:sanctum']],
    function () {

        //-------  for app  ------------
        // App route
        Route::get('app-get-electronic-categories', [ElectronicCategoryController::class, 'indexForApp']);
        // App route for getting electronic brands
        Route::get('app-get-electronic-brands', [ElectronicBrandController::class, 'indexForApp']);

        // App route for getting electronic types
        Route::get('app-get-electronic-types', [ElectronicTypeController::class, 'indexForApp']);

        Route::apiResource('inventory-types', InventoryTypeController::class);

        // Electronic Categories API Routes
        Route::apiResource('electronic-categories', ElectronicCategoryController::class);

        // Electronic Brands API Routes
        Route::apiResource('electronic-brands', ElectronicBrandController::class);

        // Electronic Types API Routes
        Route::apiResource('electronic-types', ElectronicTypeController::class);

        //================ notifications =======================
        Route::post('send-notification', [NotificationController::class, 'sendNotification']);

        //====================== faqs =========================
        Route::Resource('faqs', FaqController::class);
        Route::get('get-faqs', [FaqController::class, 'get_faqs']);

        //======================== dashboard statistics ===========================
        Route::get('order-statistics', [StatisticsCardsController::class, 'getOrderStatistics']);
        Route::get('package-statistics', [StatisticsCardsController::class, 'getPackageStatistics']);
        Route::get('customer-statistics', [StatisticsCardsController::class, 'getCustomerStatistics']);
        // Route::get('transaction-statistics', [StatisticsCardsController::class, 'getTransactionStatistics']);

        // Route to get product stats
        Route::get('product-barchat-stats', [BarChartsController::class, 'getProductStats']);

        // Route to get customer stats
        Route::get('customer-barchart-stats', [BarChartsController::class, 'getCustomerStats']);

        //==================  product routes ============================
        Route::resource('product-categories', ProductCategoryController::class);
        Route::resource('category-brands', CategoryBrandController::class);
        Route::resource('product-types', ProductTypeController::class);
        Route::resource('products', ProductController::class);

        // =============  explore section routes =============
        Route::resource('explore-categories', ExploreCategoryController::class);
        Route::resource('explore-category-blogs', ExploreCategoryBlogController::class);

        //messages for the chatroom
        Route::post('/messages', [MessageController::class, 'sendMessage']);
        Route::get('/messages', [MessageController::class, 'getMessages']);
        Route::patch('/messages/{id}/read', [MessageController::class, 'markAsRead']);
        Route::delete('/messages/{id}', [MessageController::class, 'deleteMessage']);

        // ===================Packages routes=========================================
        Route::resource('packages', PackageController::class);
        Route::get('my-packages', [PackageController::class, 'myPackages']);
        Route::post('/confirm-package-receipt/{id}', [PackageController::class, 'confirmPackageReceipt']);
        Route::post('/cancel-package-order/{id}', [PackageController::class, 'cancelPackageOrder']);
        Route::resource('package-payments', PackagePaymentController::class);

        //======================== User Management =================================
        Route::Resource('users', UserController::class);

        Route::get('get-logged-in-user', [AuthController::class, 'checkLoginStatus']);

        Route::post('/profile-photo', [UserController::class, 'update_profile_photo']);
        Route::post('/profile-update/{id}', [UserController::class, 'profile_update']);

        Route::post('/change-password/{id}', [UserController::class, 'resetPassword']);

        Route::post('/save-token', [UserController::class, 'SaveToken']);

        //=============== orders transactions ========================
        // Route::apiResource('orders', OrderController::class)->except(['store']);

        //=============== orders transactions ========================
        Route::apiResource('orders', OrderController::class);
        Route::get('my-orders', [OrderController::class, 'get_orders']);
        Route::post('/confirm-receipt/{id}', [OrderController::class, 'confirmReceipt']);
        Route::post('/cancel-order/{id}', [OrderController::class, 'cancelOrder']);
        Route::get('/customer-orders-with-balance', [OrderController::class, 'showCustomersOrdersWithBalance']);

        Route::get('/calculate-overall-balance', [OrderController::class, 'calculateOverallBalance']);
        Route::get('/orders-with-balance', [OrderController::class, 'showOrdersWithBalance']);

        // ================================Payment Apis============================
        // for the Admin
        Route::resource('payments', PaymentController::class);

        Route::post('/orders/{orderId}/record-payment', [PaymentController::class, 'recordPayment']);
        // for customer
        Route::get('my-payments', [PaymentController::class, 'get_payments']);
        Route::get('my-package-payments', [PackagePaymentController::class, 'get_package_payments']);

        //=============== Referals ========================
        Route::apiResource('referals', ReferalController::class);

        //Roles AND Permisions
        Route::get('/roles', [UserRolesController::class, 'getAssignableRoles']);

        // Sync permision to roles
        Route::get('roles-with-modified-permissions', [UserRolesController::class, 'getRolesWithModifiedPermissions']);

        Route::post('sync-permissions-to-role', [UserRolesController::class, 'syncPermissionsToRole']);

        Route::Resource('users-roles', UserRolesController::class);
        Route::Post('users-roles-addPermissionsToRole', [UserRolesController::class, 'addPermissionsToRole']);
        Route::Post('users-roles-deletePermissionFromRole', [UserRolesController::class, 'deletePermissionFromRole']);

        Route::Resource('users-permissions', UserPermissionsController::class);
        Route::get('users-permissions-permissionNotInCurrentRole/{id}', [UserPermissionsController::class, 'permissionNotInCurrentRole']);
    }
);

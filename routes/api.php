<?php

use App\Http\Controllers\API\BrandAccessoryController;
use App\Http\Controllers\API\CategoryBrandController;
use App\Http\Controllers\API\CategoryBrandOptionController;
use App\Http\Controllers\API\CategoryBrandOptionProductController;
use App\Http\Controllers\API\ExploreCategoryBlogController;
use App\Http\Controllers\API\ExploreCategoryController;
use App\Http\Controllers\API\ProductCategoryController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\UserPermissionsController;
use App\Http\Controllers\API\UserRolesController;
use App\Http\Controllers\AuthController;
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
Route::post('/third-party-login-auth', [AuthController::class, 'thirdPartyLoginAuthentication'])->name('thirdPartyLoginAuthentication');
Route::post('/third-party-register-auth', [AuthController::class, 'thirdPartyRegisterAuthentication'])->name('thirdPartyRegisterAuthentication');

Route::post('forgot-password', [PasswordResetController::class, 'forgetPassword']);
Route::get('/reset-password', [PasswordResetController::class, 'handleresetPasswordLoad']);
Route::post('/reset-password', [PasswordResetController::class, 'handlestoringNewPassword']);

//==================  product routes ============================
Route::resource('product-categories', ProductCategoryController::class)->only(['index']);
Route::resource('category-brands', CategoryBrandController::class)->only(['index']);
Route::resource('brand-accessories', BrandAccessoryController::class)->only(['index']);
Route::resource('category-brand-options', CategoryBrandOptionController::class)->only(['index']);
Route::resource('category-brand-option-products', CategoryBrandOptionProductController::class)->only(['index']);

 // =============  explore section routes =============
 Route::resource('explore-categories', ExploreCategoryController::class)->only(['index']);
 Route::resource('explore-category-blogs', ExploreCategoryBlogController::class)->only(['index']);



// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

//=============================== private routes ==================================
Route::group(
    ['middleware' => ['auth:sanctum']],
    function () {
        //==================  product routes ============================
        Route::resource('product-categories', ProductCategoryController::class);
        Route::resource('category-brands', CategoryBrandController::class);
        Route::resource('brand-accessories', BrandAccessoryController::class);
        Route::resource('category-brand-options', CategoryBrandOptionController::class);
        Route::resource('category-brand-option-products', CategoryBrandOptionProductController::class);

        // =============  explore section routes =============
        Route::resource('explore-categories', ExploreCategoryController::class);
        Route::resource('explore-category-blogs', ExploreCategoryBlogController::class);


        //======================== User Management =================================
        Route::Resource('users', UserController::class);

        Route::get('get-logged-in-user', [AuthController::class, 'checkLoginStatus']);

        Route::post('/profile-photo', [UserController::class, 'update_profile_photo']);
        Route::post('/profile-update/{id}', [UserController::class, 'profile_update']);
        
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

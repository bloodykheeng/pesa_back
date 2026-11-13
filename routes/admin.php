<?php

/*
|--------------------------------------------------------------------------
| Admin dashboard API: /api/admin/*
|--------------------------------------------------------------------------
| Used only by the Next.js admin (pesa_admin). Paged, filtered and sorted lists,
| View endpoints, permission checks. Never called by the mobile app, so these
| responses can change with the dashboard.
*/

use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:sanctum', 'role:Admin|Editor'])->name('admin.')->group(function () {
    //
});

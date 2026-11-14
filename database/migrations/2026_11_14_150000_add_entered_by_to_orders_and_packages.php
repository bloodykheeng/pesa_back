<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staff member who typed an order or a package in from the dashboard (re-entering what the Hostinger loss took,
 * or one taken over the phone). Null = the customer placed it in the app. `created_by` stays the customer either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'packages'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('entered_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['orders', 'packages'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('entered_by');
            });
        }
    }
};

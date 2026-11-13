<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the admin dashboard's lists and dashboard cards: they filter orders and
 * packages by status and date, and order every list by newest first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('status');
            $table->index('payment_status');
            $table->index('delivery_status');
            $table->index('payment_mode');
            $table->unique('order_number');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('payment_status');
            $table->index('delivery_status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('package_payments', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['status']);
            $table->dropIndex(['payment_status']);
            $table->dropIndex(['delivery_status']);
            $table->dropIndex(['payment_mode']);
            $table->dropUnique(['order_number']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['payment_status']);
            $table->dropIndex(['delivery_status']);
        });

        foreach (['payments', 'package_payments', 'users', 'products'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropIndex(['created_at']));
        }
    }
};

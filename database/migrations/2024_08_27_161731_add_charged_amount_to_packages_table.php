<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->decimal('charged_amount', 10, 2)->default(0);
            $table->decimal('balance_due', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->string('payment_status')->nullable();
            $table->string('delivery_status')->nullable();
            $table->string('package_number')->nullable();
            $table->string('payment_mode')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('charged_amount');
            $table->dropColumn('balance_due');
            $table->dropColumn('amount_paid');
            $table->dropColumn('payment_status');
            $table->dropColumn('delivery_status');
            $table->dropColumn('package_number');
            $table->dropColumn('payment_mode');
        });
    }
};
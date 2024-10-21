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
        Schema::table('products', function (Blueprint $table) {
            // Modify the existing columns to make them nullable
            $table->unsignedBigInteger('category_brands_id')->nullable()->change();
            $table->unsignedBigInteger('product_types_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Reverse the nullable change
            $table->unsignedBigInteger('category_brands_id')->nullable(false)->change();
            $table->unsignedBigInteger('product_types_id')->nullable(false)->change();
        });
    }
};
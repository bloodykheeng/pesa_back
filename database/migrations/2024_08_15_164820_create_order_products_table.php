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
        Schema::create('order_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 15, 2);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->integer('quantity');
            $table->timestamps();

            // Tracking who created and updated the fields
            $table->unsignedBigInteger('created_by')->nullable();

            // Foreign key constraints
            $table->foreign('product_id')->references('id')->on('products')->onDelete('SET NULL');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('CASCADE');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('SET NULL');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_products');
    }
};

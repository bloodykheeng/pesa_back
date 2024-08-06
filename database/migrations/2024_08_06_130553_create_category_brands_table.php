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
        Schema::create('category_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('status')->default('active')->index();
            $table->string('photo_url')->nullable();
            $table->text('details')->nullable();
            $table->unsignedBigInteger('product_categories_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Foreign Key
            $table->foreign('product_categories_id')->references('id')->on('product_categories')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_brands');
    }
};
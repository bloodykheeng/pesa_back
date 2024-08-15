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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('status')->default('active')->index();
            $table->string('photo_url')->nullable();
            $table->string('cloudinary_photo_url')->nullable();
            $table->string('cloudinary_photo_public_id')->nullable();
            $table->decimal('price', 8, 2);
            $table->integer('quantity');
            $table->text('details')->nullable();
            $table->unsignedBigInteger('category_brands_id');
            $table->unsignedBigInteger('product_types_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Foreign Key Constraints
            $table->foreign('category_brands_id')->references('id')->on('category_brands')->onDelete('cascade');
            $table->foreign('product_types_id')->references('id')->on('product_types')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

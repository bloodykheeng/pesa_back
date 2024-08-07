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
        Schema::create('category_brand_option_products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('status')->default('active')->index();
            $table->string('photo_url')->nullable();
            $table->decimal('price', 8, 2);
            $table->integer('quantity');
            $table->text('details')->nullable();
            $table->unsignedBigInteger('category_brand_options_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Foreign Key
            $table->foreign('category_brand_options_id')->references('id')->on('category_brand_options')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_brand_option_products');
    }
};

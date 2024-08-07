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
        Schema::create('explore_category_blogs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('status')->default('active')->index();
            $table->text('details')->nullable();
            $table->string('photo_url')->nullable();
            $table->unsignedBigInteger('explore_categories_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Foreign Key
            $table->foreign('explore_categories_id')->references('id')->on('explore_categories')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('explore_category_blogs');
    }
};
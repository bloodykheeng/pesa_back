<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            // Adding unsigned big integers for the foreign key columns
            $table->unsignedBigInteger('electronic_category_id')->nullable();
            $table->unsignedBigInteger('electronic_brand_id')->nullable();
            $table->unsignedBigInteger('electronic_type_id')->nullable();

            // Defining foreign key constraints
            $table->foreign('electronic_category_id')->references('id')->on('electronic_categories')->onDelete('cascade');
            $table->foreign('electronic_brand_id')->references('id')->on('electronic_brands')->onDelete('cascade');
            $table->foreign('electronic_type_id')->references('id')->on('electronic_types')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            // Dropping the foreign key constraints first
            $table->dropForeign(['electronic_category_id']);
            $table->dropForeign(['electronic_brand_id']);
            $table->dropForeign(['electronic_type_id']);

            // Dropping the columns
            $table->dropColumn(['electronic_category_id', 'electronic_brand_id', 'electronic_type_id']);
        });
    }
};

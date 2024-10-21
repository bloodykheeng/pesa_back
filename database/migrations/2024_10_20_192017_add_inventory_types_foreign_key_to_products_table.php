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
            // Add the nullable foreign key to inventory_types table
            $table->unsignedBigInteger('inventory_types_id')->nullable();

            // Define the foreign key constraint
            $table->foreign('inventory_types_id')->references('id')->on('inventory_types')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop the foreign key constraint and the column
            $table->dropForeign(['inventory_types_id']);
            $table->dropColumn('inventory_types_id');
        });
    }
};

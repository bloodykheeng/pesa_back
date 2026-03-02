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
        Schema::table('users', function (Blueprint $table) {

            // Indexes for search & filtering
            if (!Schema::hasColumn('users', 'name')) {
                return;
            }

            $table->index('name');
            $table->index('email');      // helps LIKE searches even if unique
            $table->index('phone');      // helps LIKE searches even if unique
            $table->index('gender');     // ✅ added
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropIndex(['email']);
            $table->dropIndex(['phone']);
            $table->dropIndex(['gender']);
        });
    }
};

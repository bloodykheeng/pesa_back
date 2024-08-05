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
        Schema::create('third_party_auth_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index();
            $table->text('provider_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('photo_url')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('third_party_auth_providers');
    }
};
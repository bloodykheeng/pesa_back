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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique()->index();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('status')->nullable()->default("deactive")->index();
            $table->timestamp('lastlogin')->nullable()->useCurrent();
            $table->string('photo_url')->nullable();
            $table->string('cloudinary_photo_url')->nullable();
            $table->string('cloudinary_photo_public_id')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            // New fields
            $table->boolean('agree')->default(false);
            $table->string('phone')->nullable()->unique();
            $table->string('gender')->nullable();
            $table->date('date_of_birth')->nullable(); // This is correct
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id(); // Add 'id' as the primary key
            $table->string('email')->unique();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
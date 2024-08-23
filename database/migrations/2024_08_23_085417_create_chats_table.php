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
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable()->index(); // Optional, name of the chat (e.g., group chat name)
            $table->boolean('is_group')->default(false); // Indicates if the chat is a group chat
            $table->unsignedBigInteger('created_by'); // ID of the user who created the chat
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps(); // created_at and updated_at

            // Foreign Key Constraints
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('SET NULL');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
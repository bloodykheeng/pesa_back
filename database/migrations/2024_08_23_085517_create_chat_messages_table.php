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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('reciver_id')->nullable();
            $table->text('content')->nullable();
            $table->boolean('is_read')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps(); // created_at and updated_at

            // Foreign Key Constraints
            $table->foreign('chat_id')->references('id')->on('chats')->onDelete('cascade'); // Foreign key to chats table
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade'); // Foreign key to users table
            $table->foreign('reciver_id')->references('id')->on('users')->onDelete('cascade'); // Foreign key to users table
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('SET NULL');

            // Optional: For fast retrieval of unread messages
            $table->index(['chat_id', 'is_read']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
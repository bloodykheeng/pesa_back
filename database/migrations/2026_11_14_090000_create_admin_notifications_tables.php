<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages staff send to customers from the admin dashboard (push to the app, email, or both),
 * one row per recipient with what happened on each channel; and Laravel's own `notifications`
 * table for the staff bell (new orders, package orders, sign-ups, finished sends).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();

            // What was sent
            $table->string('title');
            $table->text('body');

            // How: push to the app (needs the customer's device token), email (needs an address)
            $table->boolean('send_push')->default(true);
            $table->boolean('send_email')->default(false);

            // To whom: all_customers | selected
            $table->string('audience', 30);

            // Progress: queued | sending | sent | failed
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('push_sent')->default(0);
            $table->unsignedInteger('push_failed')->default(0);
            $table->unsignedInteger('email_sent')->default(0);
            $table->unsignedInteger('email_failed')->default(0);
            $table->timestamp('finished_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('admin_notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_notification_id')->constrained('admin_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Per channel: pending | sent | failed | skipped (no device token / no email)
            $table->string('push_status', 20)->nullable();
            $table->string('email_status', 20)->nullable();
            $table->string('error', 500)->nullable();

            $table->timestamps();
            $table->unique(['admin_notification_id', 'user_id'], 'admin_notif_recipient_unique');
            $table->index(['email_status', 'updated_at'], 'admin_notif_recipient_email_day');
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_recipients');
        Schema::dropIfExists('admin_notifications');
        Schema::dropIfExists('notifications');
    }
};

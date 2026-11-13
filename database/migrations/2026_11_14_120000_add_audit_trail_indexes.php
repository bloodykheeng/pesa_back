<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail list (/api/admin/audit-trail) sorts by time and filters by event and request.
 * activity_log already has its subject, causer and log_name (module) indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index('created_at', 'activity_log_created_at_index');
            $table->index('event', 'activity_log_event_index');
            $table->index('batch_uuid', 'activity_log_batch_uuid_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_created_at_index');
            $table->dropIndex('activity_log_event_index');
            $table->dropIndex('activity_log_batch_uuid_index');
        });
    }
};

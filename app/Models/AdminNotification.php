<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A message staff sent to customers from the dashboard (push, email or both). */
class AdminNotification extends Model implements HasSnapshotSchema
{
    protected $guarded = ['id'];

    protected $casts = [
        'send_push' => 'boolean',
        'send_email' => 'boolean',
        'finished_at' => 'datetime',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(AdminNotificationRecipient::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** How the audit trail describes this record (docs/audit-trail.md). */
    public static function snapshotSchema(): array
    {
        return [
            'title' => ['label' => 'Title', 'type' => 'string'],
            'body' => ['label' => 'Message', 'type' => 'text'],
            'audience' => ['label' => 'Sent to', 'type' => 'enum', 'labels' => ['all_customers' => 'All customers', 'selected' => 'Chosen customers']],
            'send_push' => ['label' => 'Push to the app', 'type' => 'boolean'],
            'send_email' => ['label' => 'Email', 'type' => 'boolean'],
            'recipients_count' => ['label' => 'Recipients', 'type' => 'number'],
            // Delivery progress, written by the sending job: shown on the message page, not as edits.
            'status' => ['label' => 'Status', 'type' => 'enum', 'hidden' => true],
            'push_sent' => ['hidden' => true], 'push_failed' => ['hidden' => true],
            'email_sent' => ['hidden' => true], 'email_failed' => ['hidden' => true],
            'finished_at' => ['hidden' => true],
        ];
    }

    public static function auditModule(): string
    {
        return 'Messages to customers';
    }

    public function auditTitle(): string
    {
        return '"' . str($this->title)->limit(60) . '"';
    }
}

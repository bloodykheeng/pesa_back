<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A message staff sent to customers from the dashboard (push, email or both). */
class AdminNotification extends Model
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
}

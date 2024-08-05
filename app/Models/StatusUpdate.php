<?php

namespace App\Models;

use Spatie\ModelStatus\Status;

class StatusUpdate extends Status
{
    protected $fillable = ['user_id', 'email', 'user_name']; // Ensure user_id and email are mass assignable

    public static function boot()
    {
        parent::boot();

        static::creating(function ($statusUpdate) {
            if (empty($statusUpdate->user_id) && auth()->check()) {
                $statusUpdate->user_id = auth()->id() ?? null;
                $statusUpdate->email = auth()->user()->email ?? null; // Set email from the authenticated user
                $statusUpdate->user_name = auth()->user()->name ?? null;
            }
        });
    }

    // Relation to User
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
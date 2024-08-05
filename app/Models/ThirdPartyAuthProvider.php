<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ThirdPartyAuthProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'provider_id',
        'user_id',
        'photo_url',
    ];

    /**
     * Get the user that owns the provider.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
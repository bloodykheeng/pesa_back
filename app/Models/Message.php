<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;
    protected $fillable = [
        'content',
        'senderId',
        'receverId',
        'is_read',
    ];

    /**
     * The user who sent the message.
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'senderId');
    }
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receverId');
    }
}

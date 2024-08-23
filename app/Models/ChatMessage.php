<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'sender_id',
        'reciver_id',
        'content',
        'is_read',
        'updated_by',
    ];

    /**
     * Define a relationship with the Chat.
     */
    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    /**
     * Define a relationship with the User who sent the message.
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Define a relationship with the User who received the message.
     */
    public function receiver()
    {
        return $this->belongsTo(User::class, 'reciver_id');
    }

    /**
     * Define a relationship with the User who last updated the message.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
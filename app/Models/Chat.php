<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_group',
        'created_by',
        'updated_by',
    ];

    /**
     * Define a relationship with the User who created the chat.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Define a relationship with the User who last updated the chat.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Define a relationship with the ChatMessage model.
     */
    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'chat_id');
    }
}
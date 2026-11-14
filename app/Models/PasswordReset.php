<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordReset extends Model
{
    use HasFactory;

    protected $table = "password_reset_tokens";
    public $timestamps = false;
    protected $primaryKey = 'email'; // Corrected property name
    protected $casts = [
        'email' => 'string',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
    protected $fillable = ['email', 'token', 'created_at', 'expires_at'];
}
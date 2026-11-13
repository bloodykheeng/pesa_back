<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasSnapshotSchema
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /** Roles that use the admin dashboard. Everyone else is a customer of the app. */
    public const STAFF_ROLES = ['Admin', 'Editor'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'lastlogin',
        'photo_url',
        'cloudinary_photo_url',
        'cloudinary_photo_public_id',
        'agree',
        'phone',
        'date_of_birth',
        'gender',
        'device_token',
        'nin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // public function vendors()
    // {
    //     return $this->hasOne(UserVendor::class, 'user_id');
    // }

    protected static function booted()
    {
        static::creating(function ($user) {
            $user->slug = static::uniqueSlug($user->name);
        });
    }

    public static function uniqueSlug($string)
    {
        $baseSlug = Str::slug($string, '-');
        if (static::where('slug', $baseSlug)->doesntExist()) {
            return $baseSlug;
        }

        $counter = 1;
        // Limiting the counter to prevent infinite loops
        while ($counter < 1000) {
            $slug = "{$baseSlug}-{$counter}";
            if (static::where('slug', $slug)->doesntExist()) {
                return $slug;
            }
            $counter++;
        }

        // Fallback if reached 1000 iterations (should ideally never happen)
        return "{$baseSlug}-" . uniqid();
    }

    public function setEmailAttribute($value)
    {
        if (empty($value)) { // will check for empty string
            $this->attributes['email'] = null;
        } else {
            $this->attributes['email'] = $value;
        }
    }

    public function providers()
    {
        return $this->hasMany(ThirdPartyAuthProvider::class, 'user_id', 'id');
    }

    /** Orders this customer placed in the app (orders.created_by). */
    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class, 'created_by');
    }

    /** Package deliveries this customer booked (packages.created_by). */
    public function packages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Package::class, 'created_by');
    }

    /** How the audit trail describes this record (docs/audit-trail.md). */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Full name', 'type' => 'string'],
            'email' => ['label' => 'Email', 'type' => 'string'],
            'phone' => ['label' => 'Phone', 'type' => 'string'],
            'role' => ['label' => 'Role', 'type' => 'string', 'current' => fn (self $user) => $user->getRoleNames()->implode(', ') ?: null],
            'status' => ['label' => 'Status', 'type' => 'enum'],
            'gender' => ['label' => 'Gender', 'type' => 'enum'],
            'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date'],
            'nin' => ['label' => 'NIN', 'type' => 'string'],
            'agree' => ['label' => 'Agreed to the terms', 'type' => 'boolean'],
            'photo_url' => ['label' => 'Photo', 'type' => 'file'],
            'password' => ['label' => 'Password', 'type' => 'secret'],
        ];
    }

    public static function auditModule(): string
    {
        return 'Accounts';
    }

    public function auditTitle(): string
    {
        return (string) ($this->name ?: $this->email);
    }
}

<?php

namespace App\Models;

use App\Traits\ShownInApp;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductType extends Model implements HasSnapshotSchema
{
    use ShownInApp;

    /** Shown in the app only when these parents are too (App\Traits\ShownInApp). */
    protected static function appParents(): array
    {
        return [];
    }

    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'status',
        'photo_url',
        'cloudinary_photo_url',
        'cloudinary_photo_public_id',
        'details',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'product_types_id', 'id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** How the audit trail describes this record (docs/audit-trail.md). */
    public static function snapshotSchema(): array
    {
        return Snapshot::catalog();
    }

    public static function auditModule(): string
    {
        return 'Gas product types';
    }

    public function auditTitle(): string
    {
        return (string) $this->name;
    }
}

<?php

namespace App\Models;

use App\Traits\ShownInApp;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ElectronicType extends Model implements HasSnapshotSchema
{
    use ShownInApp;

    /** Shown in the app only when these parents are too (App\Traits\ShownInApp). */
    protected static function appParents(): array
    {
        return [
            'electronicBrand' => 'electronic_brands_id',
        ];
    }

    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'status',
        'photo_url',
        'details',
        'electronic_brands_id',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    public function electronicBrand()
    {
        return $this->belongsTo(ElectronicBrand::class, 'electronic_brands_id');
    }

    /** Products listed under this series (products.electronic_type_id). */
    public function products()
    {
        return $this->hasMany(Product::class, 'electronic_type_id');
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
        return Snapshot::catalog(['electronic_brands_id' => ['label' => 'Brand', 'type' => 'relation', 'resolve' => Snapshot::nameOf(ElectronicBrand::class)]]);
    }

    public static function auditModule(): string
    {
        return 'Fuel types';
    }

    public function auditTitle(): string
    {
        return (string) $this->name;
    }
}

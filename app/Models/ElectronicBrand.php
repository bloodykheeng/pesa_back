<?php

namespace App\Models;

use App\Traits\ShownInApp;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ElectronicBrand extends Model implements HasSnapshotSchema
{
    use ShownInApp;

    /** Shown in the app only when these parents are too (App\Traits\ShownInApp). */
    protected static function appParents(): array
    {
        return [
            'electronicCategory' => 'electronic_categories_id',
        ];
    }

    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'status',
        'photo_url',
        'details',
        'electronic_categories_id',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    public function electronicCategory()
    {
        return $this->belongsTo(ElectronicCategory::class, 'electronic_categories_id');
    }

    public function types()
    {
        return $this->hasMany(ElectronicType::class, 'electronic_brands_id');
    }

    /** Products listed under this brand (products.electronic_brand_id). */
    public function products()
    {
        return $this->hasMany(Product::class, 'electronic_brand_id');
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
        return Snapshot::catalog(['electronic_categories_id' => ['label' => 'Category', 'type' => 'relation', 'resolve' => Snapshot::nameOf(ElectronicCategory::class)]]);
    }

    public static function auditModule(): string
    {
        return 'Fuel brands';
    }

    public function auditTitle(): string
    {
        return (string) $this->name;
    }
}

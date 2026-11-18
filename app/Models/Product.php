<?php

namespace App\Models;

use App\Traits\ShownInApp;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model implements HasSnapshotSchema
{
    use ShownInApp;

    /** Shown in the app only when these parents are too (App\Traits\ShownInApp). */
    protected static function appParents(): array
    {
        return [
            'categoryBrand' => 'category_brands_id',
            'productType' => 'product_types_id',
            'inventoryType' => 'inventory_types_id',
            'electronicCategory' => 'electronic_category_id',
            'electronicBrand' => 'electronic_brand_id',
            'electronicType' => 'electronic_type_id',
        ];
    }

    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'photo_url',
        'cloudinary_photo_url',
        'cloudinary_photo_public_id',
        'price',
        'quantity',
        'details',
        'category_brands_id',
        'product_types_id',
        'inventory_types_id',
        'electronic_category_id',
        'electronic_brand_id',
        'electronic_type_id',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    public function categoryBrand()
    {
        return $this->belongsTo(CategoryBrand::class, 'category_brands_id');
    }

    public function productType()
    {
        return $this->belongsTo(ProductType::class, 'product_types_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Relationship to InventoryType (assuming you have an InventoryType model)
    public function inventoryType()
    {
        return $this->belongsTo(InventoryType::class, 'inventory_types_id');
    }

    // Relationship to ElectronicCategory (assuming you have an ElectronicCategory model)
    public function electronicCategory()
    {
        return $this->belongsTo(ElectronicCategory::class, 'electronic_category_id');
    }

    // Relationship to ElectronicBrand (assuming you have an ElectronicBrand model)
    public function electronicBrand()
    {
        return $this->belongsTo(ElectronicBrand::class, 'electronic_brand_id');
    }

    // Relationship to ElectronicType (assuming you have an ElectronicType model)
    public function electronicType()
    {
        return $this->belongsTo(ElectronicType::class, 'electronic_type_id');
    }

    /** How the audit trail describes this record (docs/audit-trail.md). */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'enum'],
            'inventory_types_id' => ['label' => 'Kind', 'type' => 'relation', 'resolve' => Snapshot::nameOf(InventoryType::class)],
            'category_brands_id' => ['label' => 'Gas / water brand', 'type' => 'relation', 'resolve' => Snapshot::nameOf(CategoryBrand::class)],
            'product_types_id' => ['label' => 'Product type', 'type' => 'relation', 'resolve' => Snapshot::nameOf(ProductType::class)],
            'electronic_category_id' => ['label' => 'Electronic category', 'type' => 'relation', 'resolve' => Snapshot::nameOf(ElectronicCategory::class)],
            'electronic_brand_id' => ['label' => 'Electronic brand', 'type' => 'relation', 'resolve' => Snapshot::nameOf(ElectronicBrand::class)],
            'electronic_type_id' => ['label' => 'Series', 'type' => 'relation', 'resolve' => Snapshot::nameOf(ElectronicType::class)],
            'price' => ['label' => 'Price', 'type' => 'money'],
            'quantity' => ['label' => 'In stock', 'type' => 'number'],
            'details' => ['label' => 'Details', 'type' => 'text'],
            'photo_url' => ['label' => 'Photo', 'type' => 'file'],
        ];
    }

    public static function auditModule(): string
    {
        return 'Products';
    }

    public function auditTitle(): string
    {
        return (string) $this->name;
    }
}

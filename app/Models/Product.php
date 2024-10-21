<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
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

}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BrandAccessory extends Model
{
    use HasFactory;

    protected $table = 'brand_accessories';

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
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function categoryBrand()
    {
        return $this->belongsTo(CategoryBrand::class, 'category_brands_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

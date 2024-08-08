<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryBrandOption extends Model
{
    use HasFactory;

    protected $table = 'category_brand_options';

    protected $fillable = [
        'name',
        'code',
        'status',
        'details',
        'category_brands_id',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    public function products()
    {
        return $this->hasMany(CategoryBrandOptionProduct::class, 'category_brand_options_id', 'id');
    }
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
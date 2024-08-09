<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExploreCategoryBlog extends Model
{
    use HasFactory;

    protected $table = 'explore_category_blogs';

    protected $fillable = [
        'name',
        'status',
        'details',
        'photo_url',
        'cloudinary_photo_url',
        'cloudinary_photo_public_id',
        'explore_categories_id',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    public function exploreCategory()
    {
        return $this->belongsTo(ExploreCategory::class, 'explore_categories_id');
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
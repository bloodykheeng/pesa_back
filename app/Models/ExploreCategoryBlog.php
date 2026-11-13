<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExploreCategoryBlog extends Model implements HasSnapshotSchema
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

    /** How the audit trail describes this record (docs/audit-trail.md). */
    public static function snapshotSchema(): array
    {
        return Snapshot::catalog(['explore_categories_id' => ['label' => 'Category', 'type' => 'relation', 'resolve' => Snapshot::nameOf(ExploreCategory::class)]], false);
    }

    public static function auditModule(): string
    {
        return 'Explore articles';
    }

    public function auditTitle(): string
    {
        return (string) $this->name;
    }
}

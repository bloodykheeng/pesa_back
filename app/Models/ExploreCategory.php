<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExploreCategory extends Model implements HasSnapshotSchema
{
    use HasFactory;

    protected $table = 'explore_categories';

    protected $fillable = [
        'name',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    public function blogs()
    {
        return $this->hasMany(ExploreCategoryBlog::class, 'explore_categories_id', 'id');
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
        return Snapshot::catalog([], false);
    }

    public static function auditModule(): string
    {
        return 'Explore categories';
    }

    public function auditTitle(): string
    {
        return (string) $this->name;
    }
}

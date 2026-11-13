<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ElectronicCategory extends Model implements HasSnapshotSchema
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'status',
        'photo_url',
        'details',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function brands()
    {
        return $this->hasMany(ElectronicBrand::class, 'electronic_categories_id');
    }

    /** How the audit trail describes this record (docs/audit-trail.md). */
    public static function snapshotSchema(): array
    {
        return Snapshot::catalog();
    }

    public static function auditModule(): string
    {
        return 'Electronic categories';
    }

    public function auditTitle(): string
    {
        return (string) $this->name;
    }
}

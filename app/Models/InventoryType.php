<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryType extends Model implements HasSnapshotSchema
{
    use HasFactory;

    // Table name (optional, only needed if different from the model name in plural form)
    protected $table = 'inventory_types';

    // Mass assignable attributes
    protected $fillable = [
        'name',
        'code',
        'status',
        'photo_url',
        'details',
        'created_by',
        'updated_by',
    ];

    // Relationships with the User model (created_by and updated_by)
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
        return Snapshot::catalog();
    }

    public static function auditModule(): string
    {
        return 'Inventory types';
    }

    public function auditTitle(): string
    {
        return (string) $this->name;
    }
}

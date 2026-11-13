<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model implements HasSnapshotSchema
{
    use HasFactory;

    protected $fillable = [
        'question',
        'answer',
        'created_by',
        'updated_by',
    ];

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
        return [
            'question' => ['label' => 'Question', 'type' => 'text'],
            'answer' => ['label' => 'Answer', 'type' => 'text'],
            'status' => ['label' => 'Status', 'type' => 'enum'],
        ];
    }

    public static function auditModule(): string
    {
        return 'FAQs';
    }

    public function auditTitle(): string
    {
        return (string) str($this->question)->limit(80);
    }
}

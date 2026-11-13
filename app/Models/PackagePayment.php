<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PackagePayment extends Model implements HasSnapshotSchema
{
    use HasFactory;

    protected $fillable = [
        'package_id', 'user_id', 'amount', 'payment_method', 'created_by',
        'updated_by', 'transaction_number', 'details',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
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
        return [
            'package_id' => ['label' => 'Package', 'type' => 'relation', 'resolve' => Snapshot::nameOf(Package::class)],
            'user_id' => ['label' => 'Customer', 'type' => 'relation', 'resolve' => Snapshot::nameOf(User::class)],
            'amount' => ['label' => 'Amount', 'type' => 'money'],
            'payment_method' => ['label' => 'Method', 'type' => 'enum', 'labels' => Snapshot::PAYMENT_METHODS],
            'transaction_number' => ['label' => 'Transaction number', 'type' => 'string'],
            'details' => ['label' => 'Details', 'type' => 'text'],
        ];
    }

    public static function auditModule(): string
    {
        return 'Package payments';
    }

    public function auditTitle(): string
    {
        return 'Payment of UGX ' . number_format((float) $this->amount) . ($this->transaction_number ? ' (' . $this->transaction_number . ')' : '');
    }
}

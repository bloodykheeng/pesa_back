<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model implements HasSnapshotSchema
{
    use HasFactory;

    protected $fillable = [
        'name',
        'pickup',
        'destination',
        'photo_url',
        'order_number',
        'cloudinary_photo_url',
        'cloudinary_photo_public_id',
        'extraInfo',
        'status',
        'created_by',
        'updated_by',
        'charged_amount',
        'balance_due',
        'amount_paid',
        'payment_status',
        'delivery_status',
        'package_number',
        'payment_mode',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    // // Helps to get the delivery status is small letters always
    // public function getDeliveryStatusAttribute($value)
    // {
    //     return strtolower($value);
    // }

    public function payments()
    {
        return $this->hasMany(PackagePayment::class);
    }

    public function calculateBalanceDue()
    {
        $this->balance_due = $this->charged_amount - $this->amount_paid;
        $this->save();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The staff member who entered it from the dashboard; null when the customer placed it in the app. */
    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** How the audit trail describes this record (docs/audit-trail.md). */
    public static function snapshotSchema(): array
    {
        return [
            'package_number' => ['label' => 'Package number', 'type' => 'string'],
            'name' => ['label' => 'What it is', 'type' => 'string'],
            'pickup' => ['label' => 'Pick up', 'type' => 'string'],
            'destination' => ['label' => 'Destination', 'type' => 'string'],
            'extraInfo' => ['label' => 'Extra information', 'type' => 'text'],
            'status' => ['label' => 'Status', 'type' => 'enum'],
            'payment_mode' => ['label' => 'Payment mode', 'type' => 'enum', 'labels' => Snapshot::PAYMENT_MODES],
            'payment_status' => ['label' => 'Payment', 'type' => 'enum'],
            'delivery_status' => ['label' => 'Delivery', 'type' => 'enum'],
            'charged_amount' => ['label' => 'Charged', 'type' => 'money'],
            'amount_paid' => ['label' => 'Paid', 'type' => 'money'],
            'balance_due' => ['label' => 'Balance due', 'type' => 'money'],
            'photo_url' => ['label' => 'Photo', 'type' => 'file'],
            'entered_by' => ['label' => 'Entered from the dashboard by', 'type' => 'relation', 'resolve' => Snapshot::nameOf(User::class)],
            'order_number' => ['label' => 'Order number', 'type' => 'string'],
        ];
    }

    public static function auditModule(): string
    {
        return 'Packages';
    }

    public function auditTitle(): string
    {
        return 'Package ' . ($this->package_number ? '#' . $this->package_number . ' ' : '') . $this->name;
    }
}

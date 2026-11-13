<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model implements HasSnapshotSchema
{
    use HasFactory;

    protected $fillable = [
        'status',
        'amount',
        'address',
        'order_number',
        'charged_amount',
        'amount_paid',
        'payment_status',
        'payment_mode',
        'delivery_status',
        'balance_due',
        'created_by',
        'updated_by',
    ];

    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class, 'order_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
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

    /** How the audit trail describes this record (docs/audit-trail.md). */
    public static function snapshotSchema(): array
    {
        return [
            'order_number' => ['label' => 'Order number', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'enum'],
            'payment_mode' => ['label' => 'Payment mode', 'type' => 'enum', 'labels' => Snapshot::PAYMENT_MODES],
            'payment_status' => ['label' => 'Payment', 'type' => 'enum'],
            'delivery_status' => ['label' => 'Delivery', 'type' => 'enum'],
            'address' => ['label' => 'Address', 'type' => 'text'],
            'amount' => ['label' => 'Amount', 'type' => 'money'],
            'charged_amount' => ['label' => 'Charged', 'type' => 'money'],
            'amount_paid' => ['label' => 'Paid', 'type' => 'money'],
            'balance_due' => ['label' => 'Balance due', 'type' => 'money'],
            'items' => [
                'label' => 'Items', 'type' => 'list',
                'columns' => [['key' => 'name', 'label' => 'Product'], ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number'], ['key' => 'price', 'label' => 'Price', 'type' => 'money']],
                'current' => fn (self $order) => $order->orderProducts()->get(['name', 'quantity', 'price'])
                    ->map(fn ($i) => ['name' => $i->name, 'quantity' => (int) $i->quantity, 'price' => (float) $i->price])->all(),
            ],
        ];
    }

    public static function auditModule(): string
    {
        return 'Orders';
    }

    public function auditTitle(): string
    {
        return 'Order #' . $this->order_number;
    }
}

<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Support\Audit\Snapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

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

    /** The staff member who entered it from the dashboard; null when the customer placed it in the app. */
    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** What an order can be narrowed to by its items: an order matches when any of its items does. */
    public const ITEM_FILTERS = ['product', 'inventory_type', 'product_category', 'electronic_category'];

    /** The order's own fields the dashboard narrows by. */
    public const STATUS_FILTERS = ['delivery_status', 'payment_status', 'payment_mode'];

    /**
     * Orders matching the dashboard's filters, the same rule wherever they apply (the dashboard, the Orders list,
     * Order payments), so a figure and the list its arrow opens count the same orders. Empty values are ignored.
     *
     * @param  array<string, mixed>  $filters  product, inventory_type, product_category, electronic_category (ids),
     *                                         delivery_status, payment_status, payment_mode
     */
    public function scopeMatching(Builder $query, array $filters): Builder
    {
        $items = array_filter(Arr::only($filters, self::ITEM_FILTERS), fn ($v) => $v !== null && $v !== '');
        if ($items) {
            $query->whereHas('orderProducts', fn (Builder $line) => self::itemMatching($line, $items));
        }
        foreach (self::STATUS_FILTERS as $column) {
            if (($filters[$column] ?? '') !== '' && ($filters[$column] ?? null) !== null) {
                $query->where($query->qualifyColumn($column), $filters[$column]);
            }
        }

        return $query;
    }

    /** Order lines whose product is the one picked, or of the kind picked. */
    public static function itemMatching(Builder $line, array $items): Builder
    {
        return $line
            ->when($items['product'] ?? null, fn ($q, $id) => $q->where('order_products.product_id', $id))
            ->when(
                array_intersect_key($items, array_flip(['inventory_type', 'product_category', 'electronic_category'])),
                fn ($q) => $q->whereHas('product', fn (Builder $p) => $p
                    ->when($items['inventory_type'] ?? null, fn ($w, $id) => $w->where('inventory_types_id', $id))
                    ->when($items['electronic_category'] ?? null, fn ($w, $id) => $w->where('electronic_category_id', $id))
                    ->when($items['product_category'] ?? null, fn ($w, $id) => $w->whereHas('categoryBrand', fn ($b) => $b->where('product_categories_id', $id)))),
            );
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
            'entered_by' => ['label' => 'Entered from the dashboard by', 'type' => 'relation', 'resolve' => Snapshot::nameOf(User::class)],
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
        return 'Order #'.$this->order_number;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
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
}
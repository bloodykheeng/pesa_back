<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
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
}
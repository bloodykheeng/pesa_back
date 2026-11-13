<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePayment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Payments staff record against an order or a package, and the push message the customer gets.
 * Mirrors the app-era rules (PaymentController::makePayment): amount_paid grows, balance_due is
 * charged - paid, and a balance of 0 marks it paid.
 */
class AdminPayments
{
    public const METHODS = ['mtn_mobile_money', 'airtel_mobile_money', 'cash', 'bank'];

    public function recordOrderPayment(Order $order, array $data, User $staff): Payment
    {
        return DB::transaction(function () use ($order, $data, $staff) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->guardAmount((float) $data['amount'], (float) $order->balance_due);

            $payment = Payment::forceCreate([
                'order_id' => $order->id,
                'user_id' => $order->created_by,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'transaction_number' => $data['transaction_number'] ?? null,
                'details' => $data['details'] ?? null,
                'created_by' => $staff->id,
                'updated_by' => $staff->id,
            ]);

            $order->amount_paid = (float) $order->amount_paid + (float) $data['amount'];
            $order->balance_due = max(0, (float) $order->charged_amount - (float) $order->amount_paid);
            if ($order->balance_due <= 0) {
                $order->status = 'completed';
                $order->payment_status = 'completed';
            }
            $order->updated_by = $staff->id;
            $order->save();

            DB::afterCommit(fn () => $this->push($order->created_by, 'Payment received', 'We received ' . number_format((float) $data['amount']) . ' UGX for order #' . $order->order_number . '.'));

            return $payment;
        });
    }

    public function recordPackagePayment(Package $package, array $data, User $staff): PackagePayment
    {
        return DB::transaction(function () use ($package, $data, $staff) {
            $package = Package::lockForUpdate()->findOrFail($package->id);
            $this->guardAmount((float) $data['amount'], (float) $package->balance_due);

            $payment = PackagePayment::forceCreate([
                'package_id' => $package->id,
                'user_id' => $package->created_by,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'transaction_number' => $data['transaction_number'] ?? null,
                'details' => $data['details'] ?? null,
                'created_by' => $staff->id,
                'updated_by' => $staff->id,
            ]);

            $package->amount_paid = (float) $package->amount_paid + (float) $data['amount'];
            $package->balance_due = max(0, (float) $package->charged_amount - (float) $package->amount_paid);
            if ($package->balance_due <= 0) {
                $package->payment_status = 'Paid';
            }
            $package->updated_by = $staff->id;
            $package->save();

            DB::afterCommit(fn () => $this->push($package->created_by, 'Payment received', 'We received ' . number_format((float) $data['amount']) . ' UGX for package #' . ($package->package_number ?: $package->order_number) . '.'));

            return $payment;
        });
    }

    /** Push to one customer's phone; a missing token or a Firebase failure is logged, never thrown. */
    public function push(?int $userId, string $title, string $body): bool
    {
        $token = $userId ? User::whereKey($userId)->value('device_token') : null;
        if (! $token) {
            return false;
        }

        try {
            app(FirebaseService::class)->sendNotification($token, $title, $body);

            return true;
        } catch (Throwable $e) {
            Log::warning('Push to user ' . $userId . ' failed: ' . $e->getMessage());

            return false;
        }
    }

    private function guardAmount(float $amount, float $balance): void
    {
        if ($balance <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nothing is owed on this one.']);
        }
        if ($amount > $balance) {
            throw ValidationException::withMessages(['amount' => 'More than the balance due (' . number_format($balance) . ' UGX).']);
        }
    }
}

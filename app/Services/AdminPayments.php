<?php

namespace App\Services;

use App\Http\Controllers\Admin\OrderController;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePayment;
use App\Models\Payment;
use App\Models\User;
use App\Support\Push;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payments staff record against an order or a package (one payment or many instalments), correct or delete, and the
 * push message the customer gets. Mirrors the app-era rules (PaymentController::makePayment): amount_paid grows,
 * balance_due is charged - paid, and a balance of 0 marks it paid.
 *
 * amount_paid moves by each payment's change (+ on record, the difference on edit, - on delete) rather than being
 * re-added from the payment rows: the old admin let staff type "Amount paid" on a package with no payment behind it
 * (2 such packages on 14/11/2026), and a re-sum would wipe those.
 *
 * `paid_at` (a UTC CarbonImmutable, or null): a payment re-entered for an earlier date is dated then, may not be
 * older than the order or package itself, and sends no push (the customer was told at the time, or never needs to be).
 */
class AdminPayments
{
    public const METHODS = ['mtn_mobile_money', 'airtel_mobile_money', 'cash', 'bank'];

    public function recordOrderPayment(Order $order, array $data, User $staff): Payment
    {
        return $this->record($order, $data, $staff);
    }

    public function recordPackagePayment(Package $package, array $data, User $staff): PackagePayment
    {
        return $this->record($package, $data, $staff);
    }

    /** Correct a payment: amount, method, reference, note, date. Never more than is owed; no push. */
    public function updatePayment(Payment|PackagePayment $payment, array $data, User $staff): Payment|PackagePayment
    {
        return DB::transaction(function () use ($payment, $data, $staff) {
            $owner = $this->ownerOf($payment);
            $old = (float) $payment->amount;
            $allowed = (float) $owner->balance_due + $old;
            if ((float) $data['amount'] > $allowed) {
                throw ValidationException::withMessages(['amount' => 'More than is owed with this payment ('.number_format($allowed).' UGX).']);
            }
            $this->guardDate($data['paid_at'] ?? null, $owner);

            $payment->forceFill([
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'transaction_number' => $data['transaction_number'] ?? null,
                'details' => $data['details'] ?? null,
                'updated_by' => $staff->id,
                ...(! empty($data['paid_at']) ? ['created_at' => $data['paid_at']] : []),
            ])->save();

            $owner->amount_paid = (float) $owner->amount_paid + (float) $data['amount'] - $old;
            $owner->updated_by = $staff->id;
            $this->settle($owner);

            return $payment;
        });
    }

    /** Remove a payment recorded by mistake: the balance grows back and the payment status follows. */
    public function deletePayment(Payment|PackagePayment $payment, User $staff): void
    {
        DB::transaction(function () use ($payment, $staff) {
            $owner = $this->ownerOf($payment);
            $payment->delete();
            $owner->amount_paid = max(0, (float) $owner->amount_paid - (float) $payment->amount);
            $owner->updated_by = $staff->id;
            $this->settle($owner);
        });
    }

    /**
     * The balance from the charge and what is paid, and the payment status with it: paid in full marks it completed
     * (an order's status too); owing again after an edit or a deleted payment puts it back to pending. A payment
     * status set by hand in the same save is kept ($keepPaymentStatus). Saves the record.
     */
    public function settle(Order|Package $owner, bool $keepPaymentStatus = false): void
    {
        $paid = (float) $owner->amount_paid;
        $owner->balance_due = max(0, (float) $owner->charged_amount - $paid);

        if (! $keepPaymentStatus) {
            $done = $owner instanceof Order ? 'completed' : 'Paid';
            if ($owner->balance_due <= 0 && $paid > 0) {
                $owner->payment_status = $done;
                if ($owner instanceof Order && $owner->status !== 'cancelled') {
                    $owner->status = 'completed';
                }
            } elseif ($owner->balance_due > 0 && in_array($owner->payment_status, ['completed', 'Paid'], true)) {
                $owner->payment_status = 'pending';
                if ($owner instanceof Order && $owner->status === 'completed') {
                    $owner->status = in_array($owner->delivery_status, ['delivered', 'received'], true) ? 'delivered' : 'pending';
                }
            }
        }

        $owner->save();
    }

    /** Push to one customer's phone, queued (App\Support\Push): the request never waits on Firebase. */
    public function push(?int $userId, string $title, string $body): bool
    {
        return Push::send($userId ? User::whereKey($userId)->value('device_token') : null, $title, $body);
    }

    private function record(Order|Package $owner, array $data, User $staff): Payment|PackagePayment
    {
        return DB::transaction(function () use ($owner, $data, $staff) {
            $owner = $owner::lockForUpdate()->findOrFail($owner->id);
            $this->guardAmount((float) $data['amount'], (float) $owner->balance_due);
            $this->guardDate($data['paid_at'] ?? null, $owner);

            $payment = $owner->payments()->forceCreate([
                'user_id' => $owner->created_by,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'transaction_number' => $data['transaction_number'] ?? null,
                'details' => $data['details'] ?? null,
                'created_by' => $staff->id,
                'updated_by' => $staff->id,
                ...(! empty($data['paid_at']) ? ['created_at' => $data['paid_at'], 'updated_at' => now()] : []),
            ]);

            $owner->amount_paid = (float) $owner->amount_paid + (float) $data['amount'];
            $owner->updated_by = $staff->id;
            $this->settle($owner);

            if (empty($data['paid_at'])) {
                $what = $owner instanceof Order ? 'order #'.$owner->order_number : 'package #'.($owner->package_number ?: $owner->order_number);
                DB::afterCommit(fn () => $this->push($owner->created_by, 'Payment received', 'We received '.number_format((float) $data['amount']).' UGX for '.$what.'.'));
            }

            return $payment;
        });
    }

    private function ownerOf(Payment|PackagePayment $payment): Order|Package
    {
        return $payment instanceof Payment
            ? Order::lockForUpdate()->findOrFail($payment->order_id)
            : Package::lockForUpdate()->findOrFail($payment->package_id);
    }

    private function guardDate(?\DateTimeInterface $paidAt, Order|Package $owner): void
    {
        if ($paidAt && $owner->created_at && $paidAt < $owner->created_at) {
            $what = $owner instanceof Order ? 'the order was placed' : 'the package was booked';
            throw ValidationException::withMessages(['paid_at' => 'Before '.$what.' ('.OrderController::kampala($owner->created_at).'): a payment comes after it.']);
        }
    }

    private function guardAmount(float $amount, float $balance): void
    {
        if ($balance <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nothing is owed on this one.']);
        }
        if ($amount > $balance) {
            throw ValidationException::withMessages(['amount' => 'More than the balance due ('.number_format($balance).' UGX).']);
        }
    }
}

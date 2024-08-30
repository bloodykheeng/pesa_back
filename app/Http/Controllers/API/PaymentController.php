<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{

    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function index(Request $request)
    {
        $query = Payment::query();

        // Eager load relationships
        $query->with('order', 'customer', 'createdBy', 'updatedBy');

        // Apply filters if present
        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->has('order_id')) {
            $query->where('order_id', $request->input('order_id'));
        }

        if ($request->has('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        if ($request->has('transaction_number')) {
            $query->where('transaction_number', $request->input('transaction_number'));
        }

        if ($request->has('created_by')) {
            $query->where('created_by', $request->input('created_by'));
        }

        if ($request->has('updated_by')) {
            $query->where('updated_by', $request->input('updated_by'));
        }

        if ($request->has('amount_min')) {
            $query->where('amount', '>=', $request->input('amount_min'));
        }

        if ($request->has('amount_max')) {
            $query->where('amount', '<=', $request->input('amount_max'));
        }

        $query->latest();
        // Fetch the filtered results
        $payments = $query->get();

        return response()->json(['data' => $payments]);
    }

    public function show($id)
    {
        $payment = Payment::with('order', 'customer', 'createdBy', 'updatedBy')->find($id);
        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }
        return response()->json(['data' => $payment]);
    }

    public function store(Request $request)
    {
        // Validate the request data
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric',
            'payment_method' => 'required|string',
            'details' => 'nullable|string',
            'transaction_number' => 'required|string|unique:payments,transaction_number',
        ]);

        try {
            // Start a database transaction
            DB::beginTransaction();

            // Fetch the order
            $order = Order::findOrFail($validated['order_id']);

            // Get all payments made towards this order
            $totalPayments = Payment::where('order_id', $order->id)->sum('amount');

            // Calculate the new total after adding the new payment amount
            $newTotalPayments = $totalPayments + $validated['amount'];

            // Check if the new total exceeds the order amount
            if ($newTotalPayments > $order->charged_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total payment amount exceeds the order total.',
                ], 400); // 400 Bad Request
            }

            // // Generate a unique transaction number
            // do {
            //     $transactionNumber = strtoupper(Str::random(10));
            // } while (Payment::where('transaction_number', $transactionNumber)->exists());

            // Create the Payment
            $payment = Payment::create([
                'order_id' => $validated['order_id'],
                'user_id' => $validated['user_id'],
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                // 'transaction_number' => $transactionNumber,
                'details' => $validated['details'],
                'transaction_number' => $validated['transaction_number'],
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // Commit the transaction if all operations succeed
            DB::commit();

            $order->amount_paid += $validated['amount'];
            $order->calculateBalanceDue();

            // If balance due is 0, mark the order as completed
            if ($order->balance_due <= 0) {
                $order->status = 'completed';
                $order->payment_status = 'completed';
                $order->save();
            }

            $user = User::find($validated['user_id']);
            $this->firebaseService->sendNotification($user->device_token, "Payment. TID  #" . $validated['transaction_number'], "You're payment of UGX " . $validated['amount'] . " for order #" . $order->order_number . " has been received.");

            // Load relationships with the payment
            $payment->load('order', 'customer', 'createdBy', 'updatedBy');

            return response()->json(['message' => 'Payment created successfully', 'data' => $payment], 201);
        } catch (\Exception $e) {
            // Rollback the transaction if an exception occurs
            DB::rollback();

            // Handle the exception, log it, or return an appropriate error response
            return response()->json(['message' => 'Failed to create payment', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $payment = Payment::find($id);
        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        // Validate the request data
        $validated = $request->validate([
            'amount' => 'nullable|numeric',
            'details' => 'nullable|string',
            'payment_method' => 'nullable|string',
        ]);

        try {
            // Start a database transaction
            DB::beginTransaction();

            // Fetch the order associated with this payment
            $order = Order::findOrFail($payment->order_id);

            // Calculate the total payments excluding the current payment being updated
            $totalPaymentsExcludingCurrent = Payment::where('order_id', $order->id)
                ->where('id', '!=', $payment->id)
                ->sum('amount');

            // Calculate the new total if the payment is updated
            $newTotalPayments = $totalPaymentsExcludingCurrent + ($validated['amount'] ?? $payment->amount);

            // Check if the new total exceeds the order amount
            if ($newTotalPayments > $order->charged_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total payment amount exceeds the order total.',
                ], 400); // 400 Bad Request
            }

            // Update the Payment
            $payment->update([
                'amount' => $validated['amount'] ?? $payment->amount,
                'payment_method' => $validated['payment_method'] ?? $payment->payment_method,
                'details' => $validated['details'],
            ]);

            // Commit the transaction if all operations succeed
            DB::commit();

            // Load relationships with the payment
            $payment->load('order', 'customer', 'createdBy', 'updatedBy');

            return response()->json(['message' => 'Payment updated successfully', 'data' => $payment]);
        } catch (\Exception $e) {
            // Rollback the transaction if an exception occurs
            DB::rollback();

            // Handle the exception, log it, or return an appropriate error response
            return response()->json(['message' => 'Failed to update payment', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $payment = Payment::find($id);
        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $payment->delete();

        return response()->json(null, 204);
    }

    public function recordPayment(Request $request, $orderId)
    {
        // Validate the request
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string',
            'transaction_number' => 'required|string',
        ]);

        // Fetch the order
        $order = Order::findOrFail($orderId);

        // Check if the payment exceeds the balance
        if ($request->amount > $order->balance_due) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount exceeds the balance due.',
            ], 400); // 400 Bad Request
        }

        // Record the payment
        $this->makePayment($order, $request->amount, $request->payment_method, $request->transaction_number);

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded successfully.',
            'data' => $order,
        ]);
    }

    public function makePayment(Order $order, $amount, $paymentMode, $transactionNumber)
    {
        // Add the payment record
        Payment::create([
            'order_id' => $order->id,
            'user_id' => $order->created_by,
            'amount' => $amount,
            'payment_mode' => $paymentMode,
            'transaction_number' => $transactionNumber,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        // Update the order's amount paid and balance due
        $order->amount_paid += $amount;
        $order->calculateBalanceDue();

        // If balance due is 0, mark the order as completed
        if ($order->balance_due <= 0) {
            $order->status = 'completed';
            $order->payment_status = 'completed';
            $order->save();
        }
    }

    public function get_payments(Request $request)
    {
        $payment = Payment::with(['order', 'order.orderProducts'])->where('user_id', Auth::user()->id)->orderBy('created_at', 'desc')->get();

        return response()->json($payment);
    }

}

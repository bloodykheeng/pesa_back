<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PackagePayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\FirebaseService;

class PackagePaymentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function index(Request $request)
    {
        $query = PackagePayment::query();

        // Eager load relationships
        $query->with('package', 'customer', 'createdBy', 'updatedBy');

        // Apply filters if present
        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->has('package_id')) {
            $query->where('package_id', $request->input('package_id'));
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

        // Fetch the filtered results
        $payments = $query->get();

        return response()->json(['data' => $payments]);
    }

    public function show($id)
    {
        $payment = PackagePayment::with('package', 'customer', 'createdBy', 'updatedBy')->find($id);
        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }
        return response()->json(['data' => $payment]);
    }

    public function store(Request $request)
    {
        // Validate the request data
        $validated = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric',
            'payment_method' => 'required|string',
            'details' => 'nullable|string',
            'transaction_number' => 'required|string|unique:package_payments,transaction_number',
        ]);

        try {
            // Start a database transaction
            DB::beginTransaction();

            // Fetch the Package
            $package = Package::findOrFail($validated['package_id']);

            // Get all payments made towards this Package
            $totalPayments = PackagePayment::where('package_id', $package->id)->sum('amount');

            // Calculate the new total after adding the new payment amount
            $newTotalPayments = $totalPayments + $validated['amount'];

            // Check if the new total exceeds the Package amount
            if ($newTotalPayments > $package->charged_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total payment amount exceeds the Package total.',
                ], 400); // 400 Bad Request
            }

            // // Generate a unique transaction number
            // do {
            //     $transactionNumber = strtoupper(Str::random(10));
            // } while (PackagePayment::where('transaction_number', $transactionNumber)->exists());

            // Create the Payment
            $payment = PackagePayment::create([
                'package_id' => $validated['package_id'],
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

            $package->amount_paid += $validated['amount'];
            $package->calculateBalanceDue();

            // If balance due is 0, mark the Package as completed
            if ($package->balance_due <= 0) {
                $package->status = 'completed';
                $package->payment_status = 'completed';
                $package->save();
            }

            $user = User::find($validated['user_id']);
            $this->firebaseService->sendNotification($user->device_token, "Payment. TID  #" . $validated['transaction_number'], "You're payment of UGX " . $validated['amount'] . " for Package #" . $package->Package_number . " has been received.");

            // Load relationships with the payment
            $payment->load('package', 'customer', 'createdBy', 'updatedBy');

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
        $payment = PackagePayment::find($id);
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

            // Fetch the Package associated with this payment
            $package = Package::findOrFail($payment->package_id);

            // Calculate the total payments excluding the current payment being updated
            $totalPaymentsExcludingCurrent = PackagePayment::where('package_id', $package->id)
                ->where('id', '!=', $payment->id)
                ->sum('amount');

            // Calculate the new total if the payment is updated
            $newTotalPayments = $totalPaymentsExcludingCurrent + ($validated['amount'] ?? $payment->amount);

            // Check if the new total exceeds the Package amount
            if ($newTotalPayments > $package->charged_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total payment amount exceeds the Package total.',
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
            $payment->load('package', 'customer', 'createdBy', 'updatedBy');

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
        $payment = PackagePayment::find($id);
        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $payment->delete();

        return response()->json(null, 204);
    }

    public function recordPayment(Request $request, $packageId)
    {
        // Validate the request
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string',
            'transaction_number' => 'required|string',
        ]);

        // Fetch the Package
        $package = Package::findOrFail($packageId);

        // Check if the payment exceeds the balance
        if ($request->amount > $package->balance_due) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount exceeds the balance due.',
            ], 400); // 400 Bad Request
        }

        // Record the payment
        $this->makePayment($package, $request->amount, $request->payment_method, $request->transaction_number);

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded successfully.',
            'data' => $package,
        ]);
    }

    public function makePayment(Package $package, $amount, $paymentMode, $transactionNumber)
    {
        // Add the payment record
        PackagePayment::create([
            'package_id' => $package->id,
            'user_id' => $package->created_by,
            'amount' => $amount,
            'payment_mode' => $paymentMode,
            'transaction_number' => $transactionNumber,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        // Update the Package's amount paid and balance due
        $package->amount_paid += $amount;
        $package->calculateBalanceDue();

        // If balance due is 0, mark the Package as completed
        if ($package->balance_due <= 0) {
            $package->status = 'completed';
            $package->payment_status = 'completed';
            $package->save();
        }
    }

    public function get_package_payments(Request $request)
    {
        $payment = PackagePayment::with(['package'])->where('user_id', Auth::user()->id)->orderBy('created_at', 'desc')->get();

        return response()->json($payment);
    }
}

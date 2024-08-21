<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */




    public function recordPayment(Request $request, $orderId)
    {
        // Validate the request
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string',
        ]);

        // Fetch the order
        $order = Order::findOrFail($orderId);

        // Check if the payment exceeds the balance
        if ($request->amount > $order->balance_due) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount exceeds the balance due.'
            ], 400); // 400 Bad Request
        }

        // Record the payment
        $this->makePayment($order, $request->amount, $request->payment_method);

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded successfully.',
            'data' => $order
        ]);
    }


    public function makePayment(Order $order, $amount, $paymentMode)
    {
        // Add the payment record
        Payment::create([
            'order_id' => $order->id,
            'user_id' => $order->created_by,
            'amount' => $amount,
            'payment_mode' => $paymentMode,
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
        $payment = Payment::with('order')->where('user_id', Auth::user()->id)->orderBy('created_at', 'desc')->get();

        return response()->json($payment);
    }





    public function destroy(string $id)
    {
        //
    }
}

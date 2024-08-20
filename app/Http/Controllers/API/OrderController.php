<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::query();

        // Eager load relationships
        $query->with('user', 'products');

        // Apply filters if present
        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        // Add more filters as needed

        $orders = $query->get();

        return response()->json(['data' => $orders]);
    }

    public function show($id)
    {
        $order = Order::with('user', 'products')->find($id);
        if (!$order) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }
        return response()->json(['data' => $order]);
    }

    public function store(Request $request)
    {
        // Validate the request data
        $validated = $request->validate([
            'amount' => 'nullable|numeric',
            'charged_amount' => 'nullable|numeric',
            'payment_mode' => 'nullable|string',
            'address' => 'required|string',
            'products' => 'nullable|array',
            'products.*.name' => 'required|string',
            'products.*.price' => 'required|numeric',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        try {
            // Check if the payment mode is BNPL
            if ($validated['payment_mode'] === 'bnpl') {
                // Check if the user has any BNPL orders with outstanding balances
                $unpaidBnplOrder = Order::where('payment_mode', 'bnpl')
                    ->where('balance_due', '>', 0)
                    ->where('created_by', Auth::id())
                    ->exists();

                if ($unpaidBnplOrder) {
                    return response()->json([
                        'message' => 'You have an existing BNPL order with an outstanding balance. Please complete the payment before placing a new order.',
                    ], 400);
                }
            }

            // Start a database transaction
            DB::beginTransaction();

            // Generate a unique order number
            do {
                $orderNumber = strtoupper(Str::random(10));
            } while (Order::where('order_number', $orderNumber)->exists());

            // Create the Order
            $order = Order::create([
                'status' => 'pending',
                'amount' => $validated['amount'],
                'charged_amount' => $validated['charged_amount'],
                'address' => $validated['address'],
                'payment_status' => 'pending',
                'delivery_status' => 'pending',
                'payment_mode' => $validated['payment_mode'],
                'balance_due' => $validated['amount'],
                'order_number' => $orderNumber,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // If products are included, create order product records
            if (isset($validated['products'])) {
                foreach ($validated['products'] as $productData) {
                    // Create each OrderProduct record
                    OrderProduct::create([
                        'product_id' => $productData['product_id'],
                        'order_id' => $order->id,
                        'quantity' => $productData['quantity'],
                        'name' => $productData['name'],
                        'price' => $productData['price'],
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                    ]);

                    // Update product quantity
                    $product = Product::find($productData['product_id']);
                    if ($product) {
                        $product->decrement('quantity', $productData['quantity']);
                    }
                }
            }

            // Commit the transaction if all operations succeed
            DB::commit();

            // Load products relationship with the order
            $order->load('products');

            return response()->json(['message' => 'Order created successfully', 'data' => $order], 201);
        } catch (\Exception $e) {
            // Rollback the transaction if an exception occurs
            DB::rollback();

            // Handle the exception, log it, or return an appropriate error response
            return response()->json(['message' => 'Failed to create order', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $order = Order::find($id);
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'charged_amount' => 'nullable|numeric',
            'payment_status' => 'nullable|string',
            'payment_mode' => 'nullable|string',
            'delivery_status' => 'nullable|string',
            'products' => 'nullable|array',
            'products.*.name' => 'required|string',
            'products.*.price' => 'required|numeric',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        try {
            // Start a database transaction
            DB::beginTransaction();

            // Update the Order
            $order->update([
                'status' => $validated['status'] ?? $order->status,
                'amount' => $validated['amount'] ?? $order->amount,
                'charged_amount' => $validated['charged_amount'] ?? $order->charged_amount,
                'payment_mode' => $validated['payment_mode'] ?? $order->payment_mode,
                'delivery_status' => $validated['delivery_status'] ?? $order->delivery_status,
                'payment_status' => $validated['payment_status'] ?? $order->payment_status,
            ]);

            // Remove old quantities
            foreach ($order->products as $product) {
                $product = Product::find($product->spare_parts_id);
                if ($product) {
                    $product->decrement('quantity', $product->quantity);
                }
            }
            // Delete existing products and create new ones
            $order->products()->delete();

            if (isset($validated['products'])) {
                foreach ($validated['products'] as $productData) {
                    OrderProduct::create([
                        'product_id' => $productData['product_id'],
                        'order_id' => $order->id,
                        'quantity' => $productData['quantity'],
                        'name' => $productData['name'],
                        'price' => $productData['price'],
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                    ]);

                    // Update spare part quantity
                    $product = Product::find($productData['product_id']);
                    if ($product) {
                        $product->increment('quantity', $productData['quantity']);
                    }
                }
            }

            // Commit the transaction if all operations succeed
            DB::commit();

            // Load products relationship with the transaction
            $order->load('products');

            return response()->json(['message' => 'Order updated successfully', 'data' => $order]);
        } catch (\Exception $e) {
            // Rollback the transaction if an exception occurs
            DB::rollback();

            // Handle the exception, log it, or return an appropriate error response
            return response()->json(['message' => 'Failed to update order', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $order = Order::find($id);
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $order->products()->delete();
        $order->delete();

        return response()->json(null, 204);
    }

    public function confirmReceipt(Request $request, $id)
    {
        // Find the order by ID
        $order = Order::find($id);

        // Check if the order exists
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        // Update the delivery status to 'received'
        $order->delivery_status = 'received';

        // Save the changes
        $order->save();

        // Return a success response
        return response()->json(['message' => 'Order status updated to received'], 200);
    }


    public function get_orders(Request $request)
    {
        $orders = Order::with('user', 'products.product')->where('created_by', Auth::user()->id)->orderBy('created_at', 'desc')->get();

        return response()->json($orders);
    }


    public function showOrdersWithBalance()
    {
        // Fetch orders with outstanding balances
        $orders = Order::where('payment_mode', 'bnpl')
            ->where('balance_due', '>', 0)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }


    public function showCustomersOrdersWithBalance()
    {
        // Fetch orders with outstanding balances
        $orders = Order::where('payment_mode', 'bnpl')
            ->where('balance_due', '>', 0)
            ->where('created_by', Auth::user()->id)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }


    public function calculateOverallBalance()
    {
        // Calculate the total outstanding balance for the current user's orders
        $totalBalance = Order::where('payment_mode', 'bnpl')
            ->where('balance_due', '>', 0)
            ->where('created_by', Auth::user()->id)
            ->sum('balance_due');

        return response()->json([
            'success' => true,
            'total_balance' => $totalBalance
        ]);
    }
}

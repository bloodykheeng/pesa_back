<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrderController extends Controller
{

    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function filterOrdersData(Request $request, $ordersQuery)
    {

        // $ordersData = collect($ordersData);
        // Filter by status
        // if ($request->has('statuses') && is_array($request->input('statuses'))) {
        //     $statuses = collect($request->input('statuses'))->pluck('value');
        //     $ordersQuery->whereIn('status', $statuses);
        // }

        // Filter by delivery statuses
        if ($request->has('deliveryStatuses') && is_array($request->input('deliveryStatuses')) && !empty($request->input('deliveryStatuses'))) {
            $deliveryStatuses = collect($request->input('deliveryStatuses'))->pluck('value');
            $ordersQuery->whereIn('delivery_status', $deliveryStatuses);
        }

        // Filter by payment statuses
        if ($request->has('paymentStatuses') && is_array($request->input('paymentStatuses')) && !empty($request->input('paymentStatuses'))) {
            $paymentStatuses = collect($request->input('paymentStatuses'))->pluck('value');
            $ordersQuery->whereIn('payment_status', $paymentStatuses);
        }

        // Filter by product categories
        if ($request->has('productCategories') && is_array($request->input('productCategories')) && !empty($request->input('productCategories'))) {
            $categoryIds = collect($request->input('productCategories'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product.categoryBrand.productCategory', function ($q) use ($categoryIds) {
                $q->whereIn('id', $categoryIds);
            });
        }

        // Filter by product category brands
        if ($request->has('productCategoryBrands') && is_array($request->input('productCategoryBrands')) && !empty($request->input('productCategoryBrands'))) {
            $brandIds = collect($request->input('productCategoryBrands'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product.categoryBrand', function ($q) use ($brandIds) {
                $q->whereIn('id', $brandIds);
            });
        }

        // Filter by products
        if ($request->has('products') && is_array($request->input('products')) && !empty($request->input('products'))) {
            $productIds = collect($request->input('products'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product', function ($q) use ($productIds) {
                $q->whereIn('id', $productIds);
            });
        }

        // Filter by product types
        if ($request->has('productTypes') && is_array($request->input('productTypes')) && !empty($request->input('productTypes'))) {
            $productTypeIds = collect($request->input('productTypes'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product.productType', function ($q) use ($productTypeIds) {
                $q->whereIn('id', $productTypeIds);
            });
        }

        // Filter by created by
        if ($request->has('createdBy')) {
            $createdBy = $request->input('createdBy');
            $ordersQuery->where('created_by', $createdBy);
        }

        // New filter: inventory types
        if ($request->has('inventoryTypes') && is_array($request->input('inventoryTypes')) && !empty($request->input('inventoryTypes'))) {
            $inventoryTypeIds = collect($request->input('inventoryTypes'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product.inventoryType', function ($q) use ($inventoryTypeIds) {
                $q->whereIn('id', $inventoryTypeIds);
            });
        }

        // New filter: electronic categories
        if ($request->has('electronicCategories') && is_array($request->input('electronicCategories')) && !empty($request->input('electronicCategories'))) {
            $electronicCategoryIds = collect($request->input('electronicCategories'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product.electronicCategory', function ($q) use ($electronicCategoryIds) {
                $q->whereIn('id', $electronicCategoryIds);
            });
        }

        // New filter: electronic brands
        if ($request->has('electronicBrands') && is_array($request->input('electronicBrands')) && !empty($request->input('electronicBrands'))) {
            $electronicBrandIds = collect($request->input('electronicBrands'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product.electronicBrand', function ($q) use ($electronicBrandIds) {
                $q->whereIn('id', $electronicBrandIds);
            });
        }

        // New filter: electronic types
        if ($request->has('electronicTypes') && is_array($request->input('electronicTypes')) && !empty($request->input('electronicTypes'))) {
            $electronicTypeIds = collect($request->input('electronicTypes'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product.electronicType', function ($q) use ($electronicTypeIds) {
                $q->whereIn('id', $electronicTypeIds);
            });
        }

        return $ordersQuery;
    }

    public function index(Request $request)
    {

        $request->validate([
            'startDate' => 'required|date',
            'endDate' => 'required|date',
        ]);

        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');

        // Validate date range
        if (isset($startDate) && isset($endDate) && Carbon::parse($startDate)->greaterThan(Carbon::parse($endDate))) {
            return response()->json(['message' => 'The startDate must be before the endDate.'], 400);
        }

        $query = Order::query();

        // return DB::connection()->getDatabaseName();
        // Eager load relationships
        // Query for orders with necessary nested relationships
        $query = Order::with([
            'orderProducts' => function ($query) {
                $query->with(['product' => function ($productQuery) {
                    $productQuery->with([
                        'categoryBrand.productCategory',
                        'productType',
                        'inventoryType',
                        'electronicCategory',
                        'electronicBrand',
                        'electronicType',
                    ]);
                }]);
            },
            'createdBy',
            'updatedBy',
        ]);

        if (isset($startDate)) {
            $query->whereDate('created_at', '>=', Carbon::parse($startDate));
        }

        if (isset($endDate)) {
            $query->whereDate('created_at', '<=', Carbon::parse($endDate));
        }
        //
        $query = $this->filterOrdersData($request, $query);

        // Add more filters as needed
        $query->latest();

        $orders = $query->get();

        return response()->json(['data' => $orders]);
    }

    public function show($id)
    {
        $order = Order::with('orderProducts.product', 'createdBy', 'updatedBy')->find($id);
        if (!$order) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }
        return response()->json(['data' => $order]);
    }

    public function store(Request $request)
    {

        $user = User::find(Auth::user()->id);

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
                    ->where('status', '!=', 'cancelled')
                    ->where('delivery_status', '!=', 'cancelled')
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

            // $orderNumber = strtoupper(Str::uuid()->toString());

            // Create the Order
            $order = Order::create([
                'status' => 'pending',
                'amount' => $validated['amount'],
                'charged_amount' => $validated['amount'],
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

            if (isset($user->device_token)) {
                try {

                    $this->firebaseService->sendNotification($user->device_token, 'New Order', 'Order ' . $orderNumber . ' created successfully', );
                } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
                    // Log the error or update the user that their device token is invalid
                    // Log::error('Device token not found for user: ' . $user->id . ' - ' . $e->getMessage());

                    // Optionally, you can notify the user to update their app or token
                    // continue; // Skip to the next user
                }
            }

            // Send notifications to all Admin users
            $admins = User::role('Admin')->get();
            foreach ($admins as $admin) {
                if ($admin->email) {
                    Mail::send('emails.newOrderAdmin', ['order' => $order], function ($message) use ($admin) {
                        $message->to($admin->email)->subject('New Order Submitted');
                    });
                }
            }

            // Load products relationship with the order
            $order->load('orderProducts');

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
                'updated_by' => Auth::id(),
            ]);

            // Commit the transaction if all operations succeed
            DB::commit();

            $user = User::find($order->created_by);
            try {

                if (isset($user->device_token)) {

                    $this->firebaseService->sendNotification($user->device_token, 'Order Update ', 'Order# ' . $order->order_number . ' status has been updated');

                    if ($order->payment_status === 'paid') {

                        $this->firebaseService->sendNotification($user->device_token, 'Order Payment ', 'Payment for order# ' . $order->order_number . ' has been received');
                    }

                    if ($order->delivery_status === 'delivered') {

                        $this->firebaseService->sendNotification($user->device_token, 'Order Delivery ', 'Your order# ' . $order->order_number . ' has been delivered');
                    }

                    if ($order->delivery_status === 'cancelled') {

                        $this->firebaseService->sendNotification($user->device_token, 'Order Cancellation ', 'Your order# ' . $order->order_number . ' has been cancelled');
                    }

                }

            } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
                // Log the error for debugging purposes
                Log::error('Device token not found for user ID: ' . $user->id . ' - ' . $e->getMessage());

                // Optionally, notify the user about the issue via email or other means
                // ...
            } catch (\Exception $e) {
                // Handle other potential exceptions
                Log::error('An error occurred while sending notifications to user ID: ' . $user->id . ' - ' . $e->getMessage());
            }

            // Load products relationship with the transaction
            $order->load('orderProducts');

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

        $order->orderProducts()->delete();
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
        $order->status = 'delivered';

        // Save the changes
        $order->save();

        $user = User::find($order->created_by);
        try {
            if (isset($user->device_token)) {
                $this->firebaseService->sendNotification($user->device_token, 'Receipt Confirmation ', 'Order# ' . $order->order_number . ' has been received.');
            }

        } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
            // Log the error for debugging purposes
            Log::error('Device token not found for user ID: ' . $user->id . ' - ' . $e->getMessage());

            // Optionally, notify the user about the issue via email or other means
            // ...
        }

        // Return a success response
        return response()->json(['message' => 'Order status updated to received'], 200);
    }

    public function cancelOrder(Request $request, $id)
    {
        // Find the order by ID
        $order = Order::find($id);

        // Check if the order exists
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        // Update the delivery status to 'received'
        $order->delivery_status = 'cancelled';
        $order->payment_status = 'cancelled';
        $order->status = 'cancelled';

        // Save the changes
        $order->save();

        $user = User::find($order->created_by);
        try {
            if (isset($user->device_token)) {
                $this->firebaseService->sendNotification($user->device_token, 'Order Cancellation ', 'Order# ' . $order->order_number . ' has been cancelled.');
            }

        } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
            // Log the error for debugging purposes
            Log::error('Device token not found for user ID: ' . $user->id . ' - ' . $e->getMessage());

            // Optionally, notify the user about the issue via email or other means
            // ...
        }

        // Return a success response
        return response()->json(['message' => 'Order status updated to cancelled'], 200);
    }

    public function get_orders(Request $request)
    {
        $orders = Order::with('createdBy', 'orderProducts.product')->where('created_by', Auth::user()->id)->orderBy('created_at', 'desc')->get();

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
            'data' => $orders,
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
            'data' => $orders,
        ]);
    }

    public function calculateOverallBalance()
    {
        // Calculate the total outstanding balance for the current user's orders
        $totalBalance = Order::where('payment_mode', 'bnpl')
            ->where('balance_due', '>', 0)
            ->where('status', '!=', 'cancelled')
            ->where('delivery_status', '!=', 'cancelled')
            ->where('created_by', Auth::user()->id)
            ->sum('balance_due');

        return response()->json([
            'success' => true,
            'total_balance' => $totalBalance,
        ]);
    }
}
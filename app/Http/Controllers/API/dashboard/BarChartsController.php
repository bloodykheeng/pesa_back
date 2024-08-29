<?php

namespace App\Http\Controllers\API\dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BarChartsController extends Controller
{
    //

    public function getProductStats(Request $request)
    {
        $status = $request->input('status');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $createdBy = $request->input('createdBy');

        if (Carbon::parse($startDate)->greaterThan(Carbon::parse($endDate))) {
            return response()->json(['error' => 'The startDate must be before the endDate.'], 400);
        }

        // Build the query with optional filters
        // Build the query with optional filters
        $query = Order::with(['products.product'])->whereHas('products.product', function ($productQuery) use ($request) {
            // Filter by product status if provided
            if ($request->input('product_status')) {
                $productQuery->where('status', $request->input('product_status'));
            }

            // Filter by product category if provided (assuming you have a category relationship or column)
            if ($request->input('category_id')) {
                $productQuery->where('category_brands_id', $request->input('category_id'));
            }

            // Add any additional filters for the product here
        });

        if ($status) {
            $query->where('status', $status);
        }

        if ($startDate) {
            $query->whereDate('created_at', '>=', Carbon::parse($startDate));
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', Carbon::parse($endDate));
        }

        if ($createdBy) {
            $query->where('created_by', $createdBy);
        }

        // Fetch the data
        $orders = $query->get();

        // Aggregate the data
        $productStats = $orders->flatMap(function ($order) {
            return $order->products->map(function ($orderProduct) {
                return [
                    'product_id' => $orderProduct->product_id,
                    'product_name' => $orderProduct->product->name,
                    'product_quantity' => $orderProduct->quantity,
                    'product_sales' => $orderProduct->quantity * $orderProduct->price,
                ];
            });
        })->groupBy('product_id')->map(function ($products) {
            return [
                'name' => $products->first()['product_name'],
                'quantity' => $products->sum('product_quantity'),
                'sales' => $products->sum('product_sales'),
            ];
        })->values();

        // Apply 'orderBy' filter
        $orderBy = $request->query('orderBy')['value'] ?? 'default';
        if ($orderBy === 'asc') {
            $productStats = $productStats->sortBy('sales');
        } elseif ($orderBy === 'desc') {
            $productStats = $productStats->sortByDesc('sales');
        }

        // Apply 'dataLimit' filter
        $dataLimit = $request->query('dataLimit')['value'] ?? 'all';
        if ($dataLimit !== 'all') {
            $dataLimit = (int) $dataLimit;
            $productStats = $productStats->take($dataLimit);
        }

        // Return the formatted data
        return response()->json(["data" => $productStats->values()->toArray(), 'requestParams' => $request->all()]);
    }

    //
    public function getCustomerStats(Request $request)
    {
        $status = $request->input('status');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $createdBy = $request->input('createdBy');

        if (Carbon::parse($startDate)->greaterThan(Carbon::parse($endDate))) {
            return response()->json(['error' => 'The startDate must be before the endDate.'], 400);
        }

        // Build the query with optional filters
        $query = Order::with('createdBy'); // Eager load the 'createdBy' relationship

        if ($status) {
            $query->where('status', $status);
        }

        if ($startDate) {
            $query->whereDate('created_at', '>=', Carbon::parse($startDate));
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', Carbon::parse($endDate));
        }

        if ($createdBy) {
            $query->where('created_by', $createdBy);
        }

        // Fetch the data and aggregate it manually
        $orders = $query->get();

        $customerStats = $orders->groupBy('created_by')->map(function ($orders, $createdBy) {
            $user = $orders->first()->createdBy;
            return [
                'name' => $user->name,
                'sales' => $orders->sum('amount_paid'),
                'quantity' => $orders->count(),
            ];
        })->values();

        // Apply 'orderBy' filter
        $orderBy = $request->query('orderBy')['value'] ?? 'default';
        if ($orderBy === 'asc') {
            $customerStats = $customerStats->sortBy('sales');
        } elseif ($orderBy === 'desc') {
            $customerStats = $customerStats->sortByDesc('sales');
        }

        // Apply 'dataLimit' filter
        $dataLimit = $request->query('dataLimit')['value'] ?? 'all';
        if ($dataLimit !== 'all') {
            $dataLimit = (int) $dataLimit;
            $customerStats = $customerStats->take($dataLimit);
        }

        // Return the formatted data
        return response()->json(["data" => $customerStats->values()->toArray(), 'requestParams' => $request->all()]);
    }

}
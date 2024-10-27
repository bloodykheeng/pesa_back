<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;

class OrdersExcellExportController extends Controller
{

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

    public function exportOrdersData(Request $request)
    {

        $request->validate([
            'startDate' => 'required|date',
            'endDate' => 'required|date',
        ]);

        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');

        // return $startDate;
        // return response()->json(['message' => 'testing', '$startDate' => $startDate, '$endDate' => $endDate], 404);

        // Validate date range
        if ($startDate && $endDate && Carbon::parse($startDate)->greaterThan(Carbon::parse($endDate))) {
            return response()->json(['error' => 'The startDate must be before the endDate.'], 400);
        }

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
        ]);

        if ($startDate) {
            $query->whereDate('created_at', '>=', Carbon::parse($startDate));
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', Carbon::parse($endDate));
        }
        $query = $this->filterOrdersData($request, $query);

        // Fetch and flatten data for Excel export
        $data = $query->get()->flatMap(function ($order) {
            return $order->orderProducts->map(function ($orderProduct) use ($order) {
                $product = $orderProduct->product;

                return [
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'amount' => $order->amount,
                    'address' => $order->address,
                    'charged_amount' => $order->charged_amount,
                    'amount_paid' => $order->amount_paid,
                    'payment_status' => $order->payment_status,
                    'payment_mode' => $order->payment_mode,
                    'delivery_status' => $order->delivery_status,
                    'balance_due' => $order->balance_due,
                    'created_by' => optional($order->createdBy)->name,
                    'created_at' => $order->created_at,

                    // Product-specific details
                    'product_name' => $product->name,
                    'product_price' => $orderProduct->price,
                    'product_quantity' => $orderProduct->quantity,
                    'product_category' => optional($product->categoryBrand->productCategory)->name,
                    'product_brand' => optional($product->categoryBrand)->name,
                    'product_type' => optional($product->productType)->name,
                    'inventory_type' => optional($product->inventoryType)->name,
                    'electronic_category' => optional($product->electronicCategory)->name,
                    'electronic_brand' => optional($product->electronicBrand)->name,
                    'electronic_type' => optional($product->electronicType)->name,
                ];
            });
        });

        return response()->json(['data' => $data]); // Replace this with actual Excel export code as needed
    }
}
<?php

namespace App\Http\Controllers\API\dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BarChartsController extends Controller
{
    //

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
        $query = Order::with(['orderProducts.product']);
        // $query = Order::query();

        if ($startDate) {
            $query->whereDate('created_at', '>=', Carbon::parse($startDate));
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', Carbon::parse($endDate));
        }

        $query = $this->filterOrdersData($request, $query);

        // Fetch the data
        $orders = $query->get();

        // Aggregate the data
        $productStats = $orders->flatMap(function ($order) {
            return $order->orderProducts->map(function ($orderProduct) {
                return [
                    'product_id' => $orderProduct->product_id,
                    // 'product_name' => $orderProduct->product->name,
                    'product_name' => $orderProduct->name,
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

        $query = $this->filterOrdersData($request, $query);

        // Fetch the data and aggregate it manually
        $orders = $query->get();

        $customerStats = $orders->groupBy('created_by')->map(function ($orders, $createdBy) {
            $user = $orders->first()->createdBy;
            return [
                'name' => $user->name,
                'sales' => $orders->sum('charged_amount'),
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
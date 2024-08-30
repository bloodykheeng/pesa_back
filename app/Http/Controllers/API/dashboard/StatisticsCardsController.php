<?php

namespace App\Http\Controllers\API\dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StatisticsCardsController extends Controller
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

        if ($request->has('deliveryStatuses') && is_array($request->input('deliveryStatuses'))) {
            $deliveryStatuses = collect($request->input('deliveryStatuses'))->pluck('value');
            $ordersQuery->whereIn('delivery_status', $deliveryStatuses);
        }
        if ($request->has('paymentStatuses') && is_array($request->input('paymentStatuses'))) {
            $paymentStatuses = collect($request->input('paymentStatuses'))->pluck('value');
            $ordersQuery->whereIn('payment_status', $paymentStatuses);
        }

        // Filter by product categories
        if ($request->has('productCategories') && is_array($request->input('productCategories'))) {
            $categoryIds = collect($request->input('productCategories'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product.categoryBrand.productCategory', function ($q) use ($categoryIds) {
                $q->whereIn('id', $categoryIds);
            });
        }

        // Filter by product category brands
        if ($request->has('productCategoryBrands') && is_array($request->input('productCategoryBrands'))) {
            $brandIds = collect($request->input('productCategoryBrands'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product.categoryBrand', function ($q) use ($brandIds) {
                $q->whereIn('id', $brandIds);
            });
        }

        // Filter by products
        if ($request->has('products') && is_array($request->input('products'))) {
            $productIds = collect($request->input('products'))->pluck('id');
            $ordersQuery->whereHas('orderProducts.product', function ($q) use ($productIds) {
                $q->whereIn('id', $productIds);
            });
        }

        // Filter by product types
        if ($request->has('productTypes') && is_array($request->input('productTypes'))) {
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

        return $ordersQuery;
    }
    //

    public function getOrderStatistics(Request $request)
    {
        // Build the initial query
        $query = Order::query();

        // Additional filters
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $createdBy = $request->input('createdBy');

        if (isset($startDate)) {
            $query->whereDate('created_at', '>=', Carbon::parse($startDate));
        }

        if (isset($endDate)) {
            $query->whereDate('created_at', '<=', Carbon::parse($endDate));
        }

        if (isset($createdBy)) {
            $query->where('created_by', $createdBy);
        }
        $query = $this->filterOrdersData($request, $query);
        // Fetch the data
        $orders = $query->get();

        // Apply additional filters
        // $orders = $this->filterSellOutOrdersData($request, $orders);

        // Calculate statistics
        $totalOrders = $query->count();
        $totalSales = $query->sum('charged_amount');

        // Return the response as JSON
        return response()->json([
            "data" => [
                'total_orders' => $totalOrders,
                'total_sales' => $totalSales,
            ],
        ]);
    }

    //
    public function getPackageStatistics(Request $request)
    {
        // Retrieve filters from the request
        $status = $request->input('status');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $createdBy = $request->input('createdBy');
        $deliveryStatuses = $request->query('deliveryStatuses');
        $paymentStatuses = $request->query('paymentStatuses');

        // Build the query with optional filters
        $query = Package::query();

        if (isset($deliveryStatuses) && is_array($deliveryStatuses)) {
            $deliveryStatuses = collect($deliveryStatuses)->pluck('value');
            $query->whereIn('delivery_status', $deliveryStatuses);
        }

        if (isset($paymentStatuses) && is_array($paymentStatuses)) {
            $paymentStatuses = collect($paymentStatuses)->pluck('value');
            $query->whereIn('payment_status', $paymentStatuses);
        }

        if (isset($startDate)) {
            $query->whereDate('created_at', '>=', Carbon::parse($startDate));
        }

        if (isset($endDate)) {
            $query->whereDate('created_at', '<=', Carbon::parse($endDate));
        }

        if (isset($createdBy)) {
            $query->where('created_by', $createdBy);
        }

        // Calculate statistics
        $totalPackages = $query->count();
        $totalSales = $query->sum('charged_amount');

        // Return the response as JSON
        return response()->json(["data" => [
            'total_packages' => $totalPackages,
            'total_sales' => $totalSales],
        ]);
    }

    public function getCustomerStatistics(Request $request)
    {
        // Retrieve filters from the request
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $deliveryStatuses = $request->input('deliveryStatuses');
        $paymentStatuses = $request->input('paymentStatuses');

        // Filter users by role and date range using Spatie
        $query = User::role(['Admin', 'Customer']);

        if (isset($startDate)) {
            $query->whereDate('created_at', '>=', Carbon::parse($startDate));
        }

        if (isset($endDate)) {
            $query->whereDate('created_at', '<=', Carbon::parse($endDate));
        }

        // Get the filtered customers
        $customers = $query->get();
        $totalCustomers = $customers->count();

        // Calculate sales made and orders made
        $totalSales = 0;
        $totalOrders = 0;

        foreach ($customers as $customer) {
            $ordersQuery = Order::where('status', 'delivered')
                ->where('created_by', $customer->id);

            $packagesQuery = Package::where('status', 'delivered')
                ->where('created_by', $customer->id);

            // Apply delivery status filter
            if (isset($deliveryStatuses) && is_array($deliveryStatuses)) {
                $deliveryStatuses = collect($deliveryStatuses)->pluck('value');
                $ordersQuery->whereIn('delivery_status', $deliveryStatuses);
                $packagesQuery->whereIn('delivery_status', $deliveryStatuses);
            }

            // Apply payment status filter
            if (isset($paymentStatuses) && is_array($paymentStatuses)) {
                $paymentStatuses = collect($paymentStatuses)->pluck('value');
                $ordersQuery->whereIn('payment_status', $paymentStatuses);
                $packagesQuery->whereIn('payment_status', $paymentStatuses);
            }

            // Execute the queries and sum the results
            $orders = $ordersQuery->get();
            $packages = $packagesQuery->get();

            $totalSales += $orders->sum('charged_amount') + $packages->sum('charged_amount');
            $totalOrders += $orders->count() + $packages->count();
        }

        // Return the response as JSON
        return response()->json(["data" => [
            'total_customers' => $totalCustomers,
            'total_sales' => $totalSales,
            'total_orders' => $totalOrders,
        ]]);
    }

    public function getTransactionStatistics(Request $request)
    {
        // Retrieve filters from the request
        $status = $request->input('status');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $deliveryStatuses = $request->input('deliveryStatuses');
        $paymentStatuses = $request->input('paymentStatuses');

        // Build the query for Orders with optional filters
        $orderQuery = Order::query();

        if (isset($status)) {
            $orderQuery->where('status', $status);
        }

        if (isset($startDate)) {
            $orderQuery->whereDate('created_at', '>=', Carbon::parse($startDate));
        }

        if (isset($endDate)) {
            $orderQuery->whereDate('created_at', '<=', Carbon::parse($endDate));
        }

        // Apply delivery status filter for Orders
        if (isset($deliveryStatuses) && is_array($deliveryStatuses)) {
            $deliveryStatuses = collect($deliveryStatuses)->pluck('value');
            $orderQuery->whereIn('delivery_status', $deliveryStatuses);
        }

        // Apply payment status filter for Orders
        if (isset($paymentStatuses) && is_array($paymentStatuses)) {
            $paymentStatuses = collect($paymentStatuses)->pluck('value');
            $orderQuery->whereIn('payment_status', $paymentStatuses);
        }

        // Build the query for Packages with optional filters
        $packageQuery = Package::query();

        if (isset($status)) {
            $packageQuery->where('status', $status);
        }

        if (isset($startDate)) {
            $packageQuery->whereDate('created_at', '>=', Carbon::parse($startDate));
        }

        if (isset($endDate)) {
            $packageQuery->whereDate('created_at', '<=', Carbon::parse($endDate));
        }

        // Apply delivery status filter for Packages
        if (isset($deliveryStatuses) && is_array($deliveryStatuses)) {
            $deliveryStatuses = collect($deliveryStatuses)->pluck('value');
            $packageQuery->whereIn('delivery_status', $deliveryStatuses);
        }

        // Apply payment status filter for Packages
        if (isset($paymentStatuses) && is_array($paymentStatuses)) {
            $paymentStatuses = collect($paymentStatuses)->pluck('value');
            $packageQuery->whereIn('payment_status', $paymentStatuses);
        }

        // Calculate statistics
        $totalOrderSales = $orderQuery->sum('charged_amount') - $orderQuery->sum('balance_due');
        $totalPackageSales = $packageQuery->sum('charged_amount') - $packageQuery->sum('balance_due');

        $totalSales = $totalOrderSales + $totalPackageSales;
        $totalTransactions = $orderQuery->count() + $packageQuery->count();

        // Return the response as JSON
        return response()->json([
            "data" => [
                'total_sales' => $totalSales,
                'total_transactions' => $totalTransactions,
            ],
        ]);
    }

}
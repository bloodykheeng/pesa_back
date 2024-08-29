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
    public function getOrderStatistics(Request $request)
    {
        // Retrieve filters from the request
        $status = $request->input('status');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $createdBy = $request->input('createdBy');

        // Build the query with optional filters
        $query = Order::query();

        if (isset($status)) {
            $query->where('status', $status);
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
        $totalOrders = $query->count();
        $totalSales = $query->sum('charged_amount');

        // Return the response as JSON
        return response()->json(["data" => [
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

        // Build the query with optional filters
        $query = Package::query();

        if (isset($status)) {
            $query->where('status', $status);
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
            $orders = Order::where('status', 'delivered')
                ->where('created_by', $customer->id)
                ->get();

            $packages = Package::where('status', 'delivered')
                ->where('created_by', $customer->id)
                ->get();

            $totalSales += $orders->sum('charged_amount') + $packages->sum('charged_amount');
            $totalOrders += $orders->count() + $packages->count();
        }

        // Return the response as JSON
        return response()->json(["data" => [
            'total_customers' => $totalCustomers,
            'total_sales' => $totalSales,
            'total_orders' => $totalOrders,
        ],

        ]);
    }

    public function getTransactionStatistics(Request $request)
    {
        // Retrieve filters from the request
        $status = $request->input('status');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');

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

        // Calculate statistics
        $totalOrderSales = $orderQuery->sum('charged_amount') - $orderQuery->sum('balance_due');
        $totalPackageSales = $packageQuery->sum('charged_amount') - $packageQuery->sum('balance_due');

        $totalSales = $totalOrderSales + $totalPackageSales;
        $totalTransactions = $orderQuery->count() + $packageQuery->count();

        // Return the response as JSON
        return response()->json(["data" => [
            'total_sales' => $totalSales,
            'total_transactions' => $totalTransactions],

        ]);
    }
}
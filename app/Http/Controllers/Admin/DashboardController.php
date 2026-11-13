<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePayment;
use App\Models\Payment;
use App\Models\User;
use App\Support\AdminList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/admin/dashboard?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Both dates optional: none means all time, which is the dashboard's default (a fixed "last 30
 * days" showed zeros once the data stopped). Days are Kampala days. Every figure matches what the
 * linked list shows with the same filters, so the counting rules below are the lists' rules.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);
        [$start, $end] = AdminList::utcRange($data['from'] ?? null, $data['to'] ?? null);
        $inPeriod = function (Builder $q, string $column = 'created_at') use ($start, $end) {
            return $q->when($start, fn ($w) => $w->where($q->qualifyColumn($column), '>=', $start))
                ->when($end, fn ($w) => $w->where($q->qualifyColumn($column), '<', $end));
        };

        $orders = $inPeriod(Order::query());
        $live = (clone $orders)->where('status', '!=', 'cancelled');
        $packages = $inPeriod(Package::query());
        $livePackages = (clone $packages)->where('status', '!=', 'cancelled');

        $orderPayments = $inPeriod(Payment::query());
        $packagePayments = $inPeriod(PackagePayment::query());

        $tz = '+03:00';
        $monthly = $inPeriod(Order::query())
            ->where('status', '!=', 'cancelled')
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', '{$tz}'), '%Y-%m') as month, COUNT(*) as orders, SUM(charged_amount) as value, SUM(amount_paid) as paid")
            ->groupBy('month')->orderBy('month')->get()
            ->map(fn ($r) => ['month' => $r->month, 'orders' => (int) $r->orders, 'value' => (float) $r->value, 'paid' => (float) $r->paid]);

        $topProducts = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('orders.status', '!=', 'cancelled')
            ->when($start, fn ($q) => $q->where('orders.created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('orders.created_at', '<', $end))
            ->selectRaw('order_products.product_id, MAX(order_products.name) as name, SUM(order_products.quantity) as quantity, SUM(order_products.quantity * order_products.price) as value')
            ->groupBy('order_products.product_id')->orderByDesc('value')->limit(5)->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'name' => $r->name, 'quantity' => (int) $r->quantity, 'value' => (float) $r->value]);

        $topCustomers = $inPeriod(Order::query())
            ->where('orders.status', '!=', 'cancelled')
            ->join('users', 'users.id', '=', 'orders.created_by')
            ->selectRaw('orders.created_by as user_id, MAX(users.name) as name, COUNT(*) as orders, SUM(orders.charged_amount) as value, SUM(orders.balance_due) as balance_due')
            ->groupBy('orders.created_by')->orderByDesc('value')->limit(5)->get()
            ->map(fn ($r) => ['user_id' => $r->user_id, 'name' => $r->name, 'orders' => (int) $r->orders, 'value' => (float) $r->value, 'balance_due' => (float) $r->balance_due]);

        $methods = (clone $orderPayments)->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as amount')->groupBy('payment_method')->get()
            ->map(fn ($r) => ['method' => $r->payment_method ?: 'not recorded', 'count' => (int) $r->count, 'amount' => (float) $r->amount]);

        return response()->json([
            'period' => ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null],
            'orders' => [
                'count' => (clone $orders)->count(),
                'cancelled' => (clone $orders)->where('status', 'cancelled')->count(),
                'value' => (float) (clone $live)->sum('charged_amount'),
                'paid' => (float) (clone $live)->sum('amount_paid'),
                'balance_due' => (float) (clone $live)->where('balance_due', '>', 0)->sum('balance_due'),
                'with_balance' => (clone $live)->where('balance_due', '>', 0)->count(),
                'by_delivery' => (clone $orders)->selectRaw('delivery_status as status, COUNT(*) as count')->groupBy('delivery_status')->pluck('count', 'status'),
            ],
            'packages' => [
                'count' => (clone $packages)->count(),
                'value' => (float) (clone $livePackages)->sum('charged_amount'),
                'paid' => (float) (clone $livePackages)->sum('amount_paid'),
                'balance_due' => (float) (clone $livePackages)->where('balance_due', '>', 0)->sum('balance_due'),
            ],
            'customers' => [
                'total' => User::role('Customer')->when($end, fn ($q) => $q->where('created_at', '<', $end))->count(),
                'new' => $inPeriod(User::role('Customer'))->count(),
            ],
            'payments' => [
                'count' => (clone $orderPayments)->count() + (clone $packagePayments)->count(),
                'amount' => (float) (clone $orderPayments)->sum('amount') + (float) (clone $packagePayments)->sum('amount'),
                'by_method' => $methods,
            ],
            'monthly' => $monthly,
            'top_products' => $topProducts,
            'top_customers' => $topCustomers,
            'data_range' => [
                'first_order' => Order::min('created_at'),
                'last_order' => Order::max('created_at'),
            ],
        ]);
    }
}

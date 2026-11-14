<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePayment;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\DateBounds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/admin/dashboard?from=YYYY-MM-DD&to=YYYY-MM-DD&product=&inventory_type=&product_category=
 *     &electronic_category=&delivery_status=&payment_status=&payment_mode=
 *
 * Every filter optional: none means all time and everything, the dashboard's default (a fixed "last 30 days"
 * showed zeros once the data stopped). Days are Kampala days. The order filters (what was ordered, the order's
 * statuses: Order::scopeMatching) narrow the order figures and order payments; packages, package payments and
 * customers follow the period only. Every figure matches what the linked list shows with the same filters, so
 * the counting rules below are the lists' rules.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'product' => 'nullable|integer',
            'inventory_type' => 'nullable|integer',
            'product_category' => 'nullable|integer',
            'electronic_category' => 'nullable|integer',
            'delivery_status' => 'nullable|string|max:40',
            'payment_status' => 'nullable|string|max:40',
            'payment_mode' => 'nullable|string|max:40',
        ]);
        $filters = Arr::only($data, [...Order::ITEM_FILTERS, ...Order::STATUS_FILTERS]);
        $items = array_filter(Arr::only($filters, Order::ITEM_FILTERS), fn ($v) => $v !== null && $v !== '');

        // Both ends inclusive, as UTC instants of the Kampala days (DateBounds, docs/timezones.md).
        [$start, $end] = DateBounds::range($data['from'] ?? null, $data['to'] ?? null);
        $inPeriod = function (Builder $q, string $column = 'created_at') use ($start, $end) {
            return $q->when($start, fn ($w) => $w->where($q->qualifyColumn($column), '>=', $start))
                ->when($end, fn ($w) => $w->where($q->qualifyColumn($column), '<=', $end));
        };
        $ordersQuery = fn () => $inPeriod(Order::query())->matching($filters);

        $orders = $ordersQuery();
        $live = (clone $orders)->where('status', '!=', 'cancelled');
        $packages = $inPeriod(Package::query());
        $livePackages = (clone $packages)->where('status', '!=', 'cancelled');

        $orderPayments = $inPeriod(Payment::query())
            ->when($filters, fn ($q) => $q->whereHas('order', fn (Builder $o) => $o->matching($filters)));
        $packagePayments = $inPeriod(PackagePayment::query());

        // Months are Kampala months: an order at 01:30 on 1 March in Kampala is February in UTC.
        $month = DateBounds::businessMonthSql('orders.created_at');
        $monthly = $ordersQuery()
            ->where('status', '!=', 'cancelled')
            ->selectRaw("{$month} as month, COUNT(*) as orders, SUM(charged_amount) as value, SUM(amount_paid) as paid")
            ->groupBy('month')->orderBy('month')->get()
            ->map(fn ($r) => ['month' => $r->month, 'orders' => (int) $r->orders, 'value' => (float) $r->value, 'paid' => (float) $r->paid]);

        // Top products: the lines of matching orders, and with a product or kind picked only those lines (the top
        // products within a category, not every product on an order that had one).
        $matchingOrderIds = $ordersQuery()->where('status', '!=', 'cancelled')->select('orders.id');
        $topProducts = DB::table('order_products')
            ->whereIn('order_products.order_id', $matchingOrderIds)
            ->when($items['product'] ?? null, fn ($q, $id) => $q->where('order_products.product_id', $id))
            ->when(
                array_intersect_key($items, array_flip(['inventory_type', 'product_category', 'electronic_category'])),
                fn ($q) => $q->whereIn('order_products.product_id', Product::query()
                    ->when($items['inventory_type'] ?? null, fn ($w, $id) => $w->where('inventory_types_id', $id))
                    ->when($items['electronic_category'] ?? null, fn ($w, $id) => $w->where('electronic_category_id', $id))
                    ->when($items['product_category'] ?? null, fn ($w, $id) => $w->whereHas('categoryBrand', fn ($b) => $b->where('product_categories_id', $id)))
                    ->select('id')),
            )
            ->selectRaw('order_products.product_id, MAX(order_products.name) as name, SUM(order_products.quantity) as quantity, SUM(order_products.quantity * order_products.price) as value')
            ->groupBy('order_products.product_id')->orderByDesc('value')->limit(5)->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'name' => $r->name, 'quantity' => (int) $r->quantity, 'value' => (float) $r->value]);

        $topCustomers = $ordersQuery()
            ->where('orders.status', '!=', 'cancelled')
            ->join('users', 'users.id', '=', 'orders.created_by')
            ->selectRaw('orders.created_by as user_id, MAX(users.name) as name, COUNT(*) as orders, SUM(orders.charged_amount) as value, SUM(orders.balance_due) as balance_due')
            ->groupBy('orders.created_by')->orderByDesc('value')->limit(5)->get()
            ->map(fn ($r) => ['user_id' => $r->user_id, 'name' => $r->name, 'orders' => (int) $r->orders, 'value' => (float) $r->value, 'balance_due' => (float) $r->balance_due]);

        $methods = (clone $orderPayments)->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as amount')->groupBy('payment_method')->get()
            ->map(fn ($r) => ['method' => $r->payment_method ?: 'not recorded', 'count' => (int) $r->count, 'amount' => (float) $r->amount]);

        return response()->json([
            'period' => ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null],
            'filtered' => (bool) array_filter($filters, fn ($v) => $v !== null && $v !== ''),
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
                'with_balance' => (clone $livePackages)->where('balance_due', '>', 0)->count(),
            ],
            'customers' => [
                'total' => User::role('Customer')->when($end, fn ($q) => $q->where('users.created_at', '<=', $end))->count(),
                'new' => $inPeriod(User::role('Customer'))->count(),
            ],
            // Order payments (narrowed by the order filters) and package payments, each the list it opens.
            'payments' => [
                'count' => (clone $orderPayments)->count(),
                'amount' => (float) (clone $orderPayments)->sum('amount'),
                'by_method' => $methods,
            ],
            'package_payments' => [
                'count' => (clone $packagePayments)->count(),
                'amount' => (float) (clone $packagePayments)->sum('amount'),
            ],
            'monthly' => $monthly,
            'top_products' => $topProducts,
            'top_customers' => $topCustomers,
            'data_range' => [
                'first_order' => ($first = Order::min('created_at')) ? Carbon::parse($first)->toIso8601String() : null,
                'last_order' => ($last = Order::max('created_at')) ? Carbon::parse($last)->toIso8601String() : null,
            ],
        ]);
    }
}

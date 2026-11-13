<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\Order;
use App\Services\AdminPayments;
use App\Support\AdminList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Orders customers place in the app. Staff follow them up: status, delivery, payments.
 */
class OrderController extends Controller
{
    public const STATUSES = ['pending', 'delivered', 'completed', 'cancelled'];

    public const PAYMENT_STATUSES = ['pending', 'Paid', 'completed', 'cancelled'];

    public const DELIVERY_STATUSES = ['pending', 'processing', 'delivered', 'received', 'cancelled'];

    public function __construct(private AdminPayments $payments) {}

    public function index(Request $request)
    {
        $query = QueryBuilder::for(Order::class)
            ->with('createdBy:id,name,phone,email')
            ->withCount('orderProducts')
            ->allowedFilters([
                AdminList::search(['order_number', 'address', 'createdBy.name', 'createdBy.phone', 'createdBy.email']),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('payment_status'),
                AllowedFilter::exact('delivery_status'),
                AllowedFilter::exact('payment_mode'),
                AllowedFilter::exact('customer', 'created_by'),
                AllowedFilter::callback('has_balance', fn (Builder $q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)
                    ? $q->where('balance_due', '>', 0)->where('status', '!=', 'cancelled')
                    : $q->where('balance_due', '<=', 0)),
                ...AdminList::dateRange(),
            ])
            ->allowedSorts(['id', 'order_number', 'amount', 'charged_amount', 'amount_paid', 'balance_due', 'status', 'payment_status', 'delivery_status', 'created_at'])
            ->defaultSort('-created_at');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    public function show(int $id)
    {
        $order = Order::with([
            'createdBy:id,name,phone,email,photo_url',
            'updatedBy:id,name',
            'orderProducts.product:id,name,photo_url',
            'payments' => fn ($q) => $q->latest(),
            'payments.createdBy:id,name',
        ])->findOrFail($id);

        return new AdminResource($order);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'payment_status' => ['sometimes', Rule::in(self::PAYMENT_STATUSES)],
            'delivery_status' => ['sometimes', Rule::in(self::DELIVERY_STATUSES)],
            'address' => 'sometimes|nullable|string|max:255',
        ]);

        $before = $order->only(['status', 'delivery_status']);
        $order->forceFill($data + ['updated_by' => $request->user()->id])->save();

        if (($data['delivery_status'] ?? null) && $data['delivery_status'] !== $before['delivery_status']) {
            $this->payments->push($order->created_by, 'Order update', 'Order #' . $order->order_number . ' is now ' . $data['delivery_status'] . '.');
        } elseif (($data['status'] ?? null) && $data['status'] !== $before['status']) {
            $this->payments->push($order->created_by, 'Order update', 'Order #' . $order->order_number . ' is now ' . $data['status'] . '.');
        }

        return response()->json(['message' => 'Order #' . $order->order_number . ' saved.', 'data' => new AdminResource($order)]);
    }

    /** POST /api/admin/orders/{id}/payments */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => ['required', Rule::in(AdminPayments::METHODS)],
            'transaction_number' => 'nullable|string|max:100',
            'details' => 'nullable|string|max:1000',
        ]);

        $payment = $this->payments->recordOrderPayment($order, $data, $request->user());

        return response()->json(['message' => 'Payment of ' . number_format((float) $payment->amount) . ' UGX recorded.', 'data' => new AdminResource($order->fresh())], 201);
    }

    /** Orders with payments are kept (cancel them instead); the rest go with their lines. */
    public function destroyMany(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer'])['ids'];

        $paid = Order::whereIn('id', $ids)->whereHas('payments')->pluck('order_number');
        if ($paid->isNotEmpty()) {
            return response()->json(['message' => 'These have payments and cannot be deleted: #' . $paid->implode(', #') . '. Cancel them instead.'], 422);
        }

        $count = DB::transaction(function () use ($ids) {
            DB::table('order_products')->whereIn('order_id', $ids)->delete();

            return Order::whereIn('id', $ids)->delete();
        });

        return response()->json(['message' => $count . ' order' . ($count === 1 ? '' : 's') . ' deleted.']);
    }
}

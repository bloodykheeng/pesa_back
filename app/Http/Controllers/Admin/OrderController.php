<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminPayments;
use App\Support\AdminList;
use App\Support\DateBounds;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Orders customers place in the app, and ones staff enter from the dashboard (re-entering what the Hostinger loss
 * took, or an order taken over the phone). Staff follow them up: status, delivery, payments.
 */
class OrderController extends Controller
{
    public const STATUSES = ['pending', 'delivered', 'completed', 'cancelled'];

    public const PAYMENT_STATUSES = ['pending', 'Paid', 'completed', 'cancelled'];

    public const DELIVERY_STATUSES = ['pending', 'processing', 'delivered', 'received', 'cancelled'];

    /** Buy now pay later, cash on delivery: what the app offers. */
    public const PAYMENT_MODES = ['bnpl', 'cod'];

    public function __construct(private AdminPayments $payments) {}

    public function index(Request $request)
    {
        $query = QueryBuilder::for(Order::class)
            ->with('createdBy:id,name,phone,email', 'enteredBy:id,name')
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
                // What was ordered (the dashboard's filters, carried by its arrows): Order::scopeMatching.
                ...array_map(fn (string $key) => AllowedFilter::callback($key, fn (Builder $q, $v) => $q->matching([$key => $v])), Order::ITEM_FILTERS),
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
            'enteredBy:id,name',
            'orderProducts.product:id,name,photo_url',
            'payments' => fn ($q) => $q->latest(),
            'payments.createdBy:id,name',
        ])->findOrFail($id);

        return new AdminResource($order);
    }

    /**
     * POST /api/admin/orders: an order entered by staff for a customer. The same shape as one placed in the app
     * (a random order number, charged = the items' total, nothing paid yet, the BNPL rule), plus:
     * - placed_at: when it was really placed (Kampala wall-clock or an instant; a bare day = its first moment),
     *   never in the future, so a lost order goes back to its date. Payments are recorded afterwards.
     * - deduct_stock: take the items out of stock, as the app does (off when stock was already corrected).
     * - entered_by: who typed it in. No push to the customer and no email to the admins: staff entered it.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'placed_at' => 'nullable|string|max:40',
            'address' => 'required|string|max:255',
            'payment_mode' => ['required', Rule::in(self::PAYMENT_MODES)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'delivery_status' => ['nullable', Rule::in(self::DELIVERY_STATUSES)],
            'deduct_stock' => 'nullable|boolean',
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')],
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.price' => 'required|numeric|min:0|max:1000000000',
        ], [
            'items.required' => 'Add at least one item.',
            'items.*.product_id.distinct' => 'This product is on the order twice: change the quantity instead.',
        ]);

        $placedAt = self::placedAt($data['placed_at'] ?? null);
        $customer = User::findOrFail($data['customer_id']);

        if ($data['payment_mode'] === 'bnpl' && Order::where('created_by', $customer->id)->where('payment_mode', 'bnpl')
            ->where('balance_due', '>', 0)->where('status', '!=', 'cancelled')->where('delivery_status', '!=', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['payment_mode' => $customer->name.' still owes on a BNPL order. Record that payment first, or enter this one as cash on delivery.']);
        }

        $staff = $request->user();
        $order = DB::transaction(function () use ($data, $placedAt, $customer, $staff) {
            $products = Product::whereIn('id', array_column($data['items'], 'product_id'))->get(['id', 'name'])->keyBy('id');
            $total = array_sum(array_map(fn ($i) => (float) $i['price'] * (int) $i['quantity'], $data['items']));

            do {
                $number = strtoupper(Str::random(10));
            } while (Order::where('order_number', $number)->exists());

            $order = Order::forceCreate([
                'order_number' => $number,
                'status' => $data['status'] ?? 'pending',
                'delivery_status' => $data['delivery_status'] ?? 'pending',
                'payment_status' => 'pending',
                'payment_mode' => $data['payment_mode'],
                'address' => $data['address'],
                'amount' => $total,
                'charged_amount' => $total,
                'amount_paid' => 0,
                'balance_due' => $total,
                'created_by' => $customer->id,
                'updated_by' => $staff->id,
                'entered_by' => $staff->id,
                'created_at' => $placedAt,
                'updated_at' => now(),
            ]);

            foreach ($data['items'] as $item) {
                OrderProduct::forceCreate([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'name' => $products[$item['product_id']]->name,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'created_by' => $customer->id,
                    'created_at' => $placedAt,
                    'updated_at' => now(),
                ]);
                if ($data['deduct_stock'] ?? true) {
                    Product::whereKey($item['product_id'])->decrement('quantity', (int) $item['quantity']);
                }
            }

            return $order;
        });

        return response()->json([
            'message' => 'Order #'.$order->order_number.' entered for '.$customer->name.'.',
            'data' => new AdminResource($order->load('createdBy:id,name,phone,email', 'enteredBy:id,name')),
        ], 201);
    }

    /** When a record entered by staff really happened: now when blank, never later than now (422). */
    public static function placedAt(?string $input, string $field = 'placed_at'): CarbonImmutable
    {
        if ($input === null || trim($input) === '') {
            return CarbonImmutable::now('UTC');
        }
        $moment = DateBounds::startInstant($input, $field);
        if ($moment->isAfter(CarbonImmutable::now('UTC')->addMinutes(5))) {
            throw ValidationException::withMessages([$field => 'This is in the future: enter when it really happened.']);
        }

        return $moment;
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
            $this->payments->push($order->created_by, 'Order update', 'Order #'.$order->order_number.' is now '.$data['delivery_status'].'.');
        } elseif (($data['status'] ?? null) && $data['status'] !== $before['status']) {
            $this->payments->push($order->created_by, 'Order update', 'Order #'.$order->order_number.' is now '.$data['status'].'.');
        }

        return response()->json(['message' => 'Order #'.$order->order_number.' saved.', 'data' => new AdminResource($order)]);
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
            'paid_at' => 'nullable|string|max:40',
        ]);
        // An earlier date (re-entering a lost payment) is recorded on that date, quietly: no push to the customer.
        $data['paid_at'] = filled($data['paid_at'] ?? null) ? OrderController::placedAt($data['paid_at'], 'paid_at') : null;

        $payment = $this->payments->recordOrderPayment($order, $data, $request->user());

        return response()->json(['message' => 'Payment of '.number_format((float) $payment->amount).' UGX recorded.', 'data' => new AdminResource($order->fresh())], 201);
    }

    /** Orders with payments are kept (cancel them instead); the rest go with their lines. */
    public function destroyMany(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer'])['ids'];

        $paid = Order::whereIn('id', $ids)->whereHas('payments')->pluck('order_number');
        if ($paid->isNotEmpty()) {
            return response()->json(['message' => 'These have payments and cannot be deleted: #'.$paid->implode(', #').'. Cancel them instead.'], 422);
        }

        // One by one, so each deleted order (with its items) is kept in the audit trail;
        // its items go with it (order_products cascades).
        $orders = Order::whereIn('id', $ids)->get();
        DB::transaction(fn () => $orders->each->delete());
        $count = $orders->count();

        return response()->json(['message' => $count.' order'.($count === 1 ? '' : 's').' deleted.']);
    }
}

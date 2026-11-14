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
use App\Support\Audit\Audit;
use App\Support\DateBounds;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * (an ORD number from the order's model, charged = the items' total, nothing paid yet, the BNPL rule), plus:
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
            // What the customer pays when it is not the items' total (a delivery fee, a discount). Empty = the total.
            'charged_amount' => 'nullable|numeric|min:0|max:1000000000',
            ...self::itemRules('required'),
        ], self::ITEM_MESSAGES);

        $placedAt = self::placedAt($data['placed_at'] ?? null);
        $customer = User::findOrFail($data['customer_id']);

        if ($data['payment_mode'] === 'bnpl') {
            self::guardBnpl($customer->id);
        }

        $staff = $request->user();
        $order = DB::transaction(function () use ($data, $placedAt, $customer, $staff) {
            $total = array_sum(array_map(fn ($i) => (float) $i['price'] * (int) $i['quantity'], $data['items']));

            $order = Order::forceCreate([
                'status' => $data['status'] ?? 'pending',
                'delivery_status' => $data['delivery_status'] ?? 'pending',
                'payment_status' => 'pending',
                'payment_mode' => $data['payment_mode'],
                'address' => $data['address'],
                'amount' => $total,
                'charged_amount' => $data['charged_amount'] ?? $total,
                'amount_paid' => 0,
                'balance_due' => $data['charged_amount'] ?? $total,
                'created_by' => $customer->id,
                'updated_by' => $staff->id,
                'entered_by' => $staff->id,
                'created_at' => $placedAt,
                'updated_at' => now(),
            ]);

            self::writeItems($order, $data['items'], (bool) ($data['deduct_stock'] ?? true), $placedAt);

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

    /**
     * POST /api/admin/orders/{id}: edit the whole order, what New sets and more, for app orders and entered ones alike.
     * Every field is optional ("sometimes"), so a status-only save still works.
     * - items: replace the lines; with adjust_stock (default on) the old quantities go back into stock and the new
     *   ones come out, so the count stays right.
     * - charged_amount: what the customer pays (the items' total, or another price agreed: delivery, a discount);
     *   never below what is already paid. The balance and the payment status follow.
     * - customer_id: moves the order and its payments to that customer. placed_at: never after its first payment.
     * A delivery or status change still sends the customer a push.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
            'placed_at' => 'sometimes|nullable|string|max:40',
            'address' => 'sometimes|nullable|string|max:255',
            'payment_mode' => ['sometimes', Rule::in(self::PAYMENT_MODES)],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'payment_status' => ['sometimes', Rule::in(self::PAYMENT_STATUSES)],
            'delivery_status' => ['sometimes', Rule::in(self::DELIVERY_STATUSES)],
            'charged_amount' => 'sometimes|numeric|min:0|max:1000000000',
            'adjust_stock' => 'nullable|boolean',
            ...self::itemRules('sometimes'),
        ], self::ITEM_MESSAGES);

        $staff = $request->user();
        [$order, $before] = DB::transaction(function () use ($data, $id, $staff) {
            $order = Order::with('orderProducts')->lockForUpdate()->findOrFail($id);
            $before = $order->only(['status', 'delivery_status']);
            $firstPayment = $order->payments()->min('created_at');

            if (array_key_exists('placed_at', $data) && filled($data['placed_at'])) {
                $placed = self::placedAt($data['placed_at']);
                if ($firstPayment && $placed->greaterThan(CarbonImmutable::parse($firstPayment, 'UTC'))) {
                    throw ValidationException::withMessages(['placed_at' => 'After its first payment ('.self::kampala($firstPayment).'): an order is placed before it is paid.']);
                }
                $order->created_at = $placed;
            }

            if (isset($data['customer_id']) && (int) $data['customer_id'] !== (int) $order->created_by) {
                $order->created_by = (int) $data['customer_id'];
                $order->payments()->update(['user_id' => $order->created_by]);
            }

            $mode = $data['payment_mode'] ?? $order->payment_mode;
            if ($mode === 'bnpl' && ($order->isDirty('created_by') || $mode !== $order->payment_mode)) {
                self::guardBnpl((int) $order->created_by, $order->id);
            }

            if (isset($data['items'])) {
                $old = self::itemsText($order->orderProducts);
                if ($data['adjust_stock'] ?? true) {
                    foreach ($order->orderProducts as $line) {
                        if ($line->product_id) {
                            Product::whereKey($line->product_id)->increment('quantity', (int) $line->quantity);
                        }
                    }
                }
                $order->orderProducts()->delete();
                $order->amount = self::writeItems($order, $data['items'], (bool) ($data['adjust_stock'] ?? true), CarbonImmutable::parse($order->created_at, 'UTC'));
                Audit::change($order, 'items', 'Items', $old, self::itemsText($order->orderProducts()->get()));
                if (! array_key_exists('charged_amount', $data)) {
                    $order->charged_amount = $order->amount;
                }
            }

            if (array_key_exists('charged_amount', $data)) {
                if ((float) $data['charged_amount'] < (float) $order->amount_paid) {
                    throw ValidationException::withMessages(['charged_amount' => 'Less than what is already paid ('.number_format((float) $order->amount_paid).' UGX).']);
                }
                $order->charged_amount = $data['charged_amount'];
            }

            // Set by hand = changed in this save (the form sends it every time).
            $manualStatus = isset($data['payment_status']) && $data['payment_status'] !== $order->payment_status;
            $order->forceFill(collect($data)->only(['address', 'payment_mode', 'status', 'payment_status', 'delivery_status'])->all());
            $order->updated_by = $staff->id;
            $order->save();
            // The balance and payment status follow the charge, unless the payment status was set by hand.
            $this->payments->settle($order, $manualStatus);

            return [$order, $before];
        });

        if (($data['delivery_status'] ?? null) && $data['delivery_status'] !== $before['delivery_status']) {
            $this->payments->push($order->created_by, 'Order update', 'Order #'.$order->order_number.' is now '.$data['delivery_status'].'.');
        } elseif (($data['status'] ?? null) && $data['status'] !== $before['status']) {
            $this->payments->push($order->created_by, 'Order update', 'Order #'.$order->order_number.' is now '.$data['status'].'.');
        }

        return response()->json(['message' => 'Order #'.$order->order_number.' saved.', 'data' => new AdminResource($order->fresh(['createdBy:id,name,phone,email', 'enteredBy:id,name']))]);
    }

    /** @return array<string, mixed> the item rules, shared by New and Edit */
    private static function itemRules(string $presence): array
    {
        return [
            'items' => $presence.'|array|min:1|max:50',
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')],
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.price' => 'required|numeric|min:0|max:1000000000',
        ];
    }

    private const ITEM_MESSAGES = [
        'items.required' => 'Add at least one item.',
        'items.min' => 'Add at least one item.',
        'items.*.product_id.distinct' => 'This product is on the order twice: change the quantity instead.',
    ];

    /** Writes the lines (named after their products), takes them out of stock when asked; returns their total. */
    private static function writeItems(Order $order, array $items, bool $deduct, CarbonImmutable $placedAt): float
    {
        $products = Product::whereIn('id', array_column($items, 'product_id'))->get(['id', 'name'])->keyBy('id');
        foreach ($items as $item) {
            OrderProduct::forceCreate([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'name' => $products[$item['product_id']]->name,
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'created_by' => $order->created_by,
                'created_at' => $placedAt,
                'updated_at' => now(),
            ]);
            if ($deduct) {
                Product::whereKey($item['product_id'])->decrement('quantity', (int) $item['quantity']);
            }
        }

        return array_sum(array_map(fn ($i) => (float) $i['price'] * (int) $i['quantity'], $items));
    }

    /** "Shell 12kg × 2 at 90,000; Elevate water × 1 at 10,000": the items as the audit trail shows a change to them. */
    private static function itemsText($lines): string
    {
        return collect($lines)->map(fn ($l) => $l->name.' × '.(int) $l->quantity.' at '.number_format((float) $l->price))->implode('; ') ?: 'none';
    }

    /** The app's rule: one unpaid buy-now-pay-later order at a time per customer. */
    private static function guardBnpl(int $customerId, ?int $except = null): void
    {
        $owes = Order::where('created_by', $customerId)->where('payment_mode', 'bnpl')->when($except, fn ($q) => $q->whereKeyNot($except))
            ->where('balance_due', '>', 0)->where('status', '!=', 'cancelled')->where('delivery_status', '!=', 'cancelled')->exists();
        if ($owes) {
            $name = User::whereKey($customerId)->value('name') ?? 'This customer';
            throw ValidationException::withMessages(['payment_mode' => $name.' still owes on a BNPL order. Record that payment first, or make this one cash on delivery.']);
        }
    }

    /** "14 Nov 2026, 7:47 pm": a stored instant on the Kampala clock, for messages. */
    public static function kampala($instant): string
    {
        return CarbonImmutable::parse($instant, 'UTC')->setTimezone(DateBounds::timezone())->format('j M Y, g:i a');
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

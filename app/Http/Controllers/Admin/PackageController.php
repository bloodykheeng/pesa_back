<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StoresPhotos;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\Package;
use App\Models\User;
use App\Services\AdminPayments;
use App\Support\AdminList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Package deliveries customers book in the app (pickup -> destination), and ones staff enter from the dashboard.
 * Staff set the price, follow delivery and record payments.
 */
class PackageController extends Controller
{
    use StoresPhotos;

    public const STATUSES = ['pending', 'processing', 'delivered', 'cancelled'];

    public const PAYMENT_STATUSES = ['pending', 'Paid', 'cancelled'];

    public const DELIVERY_STATUSES = ['pending', 'processing', 'delivered', 'received', 'cancelled'];

    public function __construct(private AdminPayments $payments) {}

    public function index(Request $request)
    {
        $query = QueryBuilder::for(Package::class)
            ->with('createdBy:id,name,phone,email', 'enteredBy:id,name')
            ->allowedFilters([
                AdminList::search(['name', 'package_number', 'order_number', 'pickup', 'destination', 'createdBy.name', 'createdBy.phone']),
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
            ->allowedSorts(['id', 'name', 'package_number', 'charged_amount', 'amount_paid', 'balance_due', 'status', 'created_at'])
            ->defaultSort('-created_at');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    public function show(int $id)
    {
        return new AdminResource(Package::with([
            'createdBy:id,name,phone,email,photo_url',
            'updatedBy:id,name',
            'enteredBy:id,name',
            'payments' => fn ($q) => $q->latest(),
            'payments.createdBy:id,name',
        ])->findOrFail($id));
    }

    /**
     * POST /api/admin/packages: a delivery entered by staff for a customer, dated when it was really booked
     * (OrderController::placedAt). Nothing paid yet; payments are recorded afterwards. No push, no admin email.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'placed_at' => 'nullable|string|max:40',
            'name' => 'required|string|max:255',
            'pickup' => 'required|string|max:255',
            'destination' => 'required|string|max:255',
            'extraInfo' => 'nullable|string|max:5000',
            'charged_amount' => 'required|numeric|min:0|max:1000000000',
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'delivery_status' => ['nullable', Rule::in(self::DELIVERY_STATUSES)],
            'photo' => self::PHOTO_RULE,
        ]);

        $placedAt = OrderController::placedAt($data['placed_at'] ?? null);
        $customer = User::findOrFail($data['customer_id']);
        $staff = $request->user();

        do {
            $number = strtoupper(Str::random(10));
        } while (Package::where('package_number', $number)->exists());

        $package = new Package;
        $package->forceFill([
            'package_number' => $number,
            'name' => $data['name'],
            'pickup' => $data['pickup'],
            'destination' => $data['destination'],
            'extraInfo' => $data['extraInfo'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'delivery_status' => $data['delivery_status'] ?? 'pending',
            'payment_status' => 'pending',
            'payment_mode' => 'unknown',
            'charged_amount' => $data['charged_amount'],
            'amount_paid' => 0,
            'balance_due' => $data['charged_amount'],
            'created_by' => $customer->id,
            'updated_by' => $staff->id,
            'entered_by' => $staff->id,
            'created_at' => $placedAt,
            'updated_at' => now(),
        ]);
        $this->applyPhoto($request, $package, 'package_photos');
        $package->save();

        return response()->json([
            'message' => 'Package #'.$number.' entered for '.$customer->name.'.',
            'data' => new AdminResource($package->load('createdBy:id,name,phone,email', 'enteredBy:id,name')),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $package = Package::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'pickup' => 'sometimes|nullable|string|max:255',
            'destination' => 'sometimes|nullable|string|max:255',
            'extraInfo' => 'sometimes|nullable|string',
            'charged_amount' => 'sometimes|numeric|min:0',
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'payment_status' => ['sometimes', Rule::in(self::PAYMENT_STATUSES)],
            'delivery_status' => ['sometimes', Rule::in(self::DELIVERY_STATUSES)],
            'photo' => self::PHOTO_RULE,
            'remove_photo' => 'nullable|boolean',
        ]);

        $before = $package->delivery_status;
        $package->forceFill(collect($data)->except(['photo', 'remove_photo'])->all() + ['updated_by' => $request->user()->id]);
        if (array_key_exists('charged_amount', $data)) {
            $package->balance_due = max(0, (float) $package->charged_amount - (float) $package->amount_paid);
        }
        $this->applyPhoto($request, $package, 'package_photos');
        $package->save();

        if (($data['delivery_status'] ?? null) && $data['delivery_status'] !== $before) {
            $this->payments->push($package->created_by, 'Package update', 'Package "'.$package->name.'" is now '.$data['delivery_status'].'.');
        }

        return response()->json(['message' => 'Package saved.', 'data' => new AdminResource($package)]);
    }

    /** POST /api/admin/packages/{id}/payments */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $package = Package::findOrFail($id);
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => ['required', Rule::in(AdminPayments::METHODS)],
            'transaction_number' => 'nullable|string|max:100',
            'details' => 'nullable|string|max:1000',
            'paid_at' => 'nullable|string|max:40',
        ]);
        // An earlier date (re-entering a lost payment) is recorded on that date, quietly: no push to the customer.
        $data['paid_at'] = filled($data['paid_at'] ?? null) ? OrderController::placedAt($data['paid_at'], 'paid_at') : null;

        $payment = $this->payments->recordPackagePayment($package, $data, $request->user());

        return response()->json(['message' => 'Payment of '.number_format((float) $payment->amount).' UGX recorded.', 'data' => new AdminResource($package->fresh())], 201);
    }

    public function destroyMany(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer'])['ids'];

        $paid = Package::whereIn('id', $ids)->whereHas('payments')->pluck('name');
        if ($paid->isNotEmpty()) {
            return response()->json(['message' => 'These have payments and cannot be deleted: '.$paid->implode(', ').'. Cancel them instead.'], 422);
        }

        $packages = Package::whereIn('id', $ids)->get();
        $packages->each(function (Package $p) {
            $p->delete();
            $this->deletePhoto($p->photo_url);
        });

        return response()->json(['message' => $packages->count().' package'.($packages->count() === 1 ? '' : 's').' deleted.']);
    }
}

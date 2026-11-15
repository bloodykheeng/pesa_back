<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\Order;
use App\Models\PackagePayment;
use App\Models\Payment;
use App\Services\AdminPayments;
use App\Support\AdminList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Money received: order payments (/api/admin/payments) and package payments (/api/admin/package-payments).
 * Payments are recorded on their order or package (one, or many instalments); here they are listed, corrected
 * (staff) and deleted (Admins), with the balance and payment status following (AdminPayments).
 */
class PaymentController extends Controller
{
    public function __construct(private AdminPayments $payments) {}

    /** POST /api/admin/payments/{id} */
    public function updateOrderPayment(Request $request, int $id): JsonResponse
    {
        return $this->correct($request, Payment::findOrFail($id));
    }

    /** POST /api/admin/package-payments/{id} */
    public function updatePackagePayment(Request $request, int $id): JsonResponse
    {
        return $this->correct($request, PackagePayment::findOrFail($id));
    }

    /** POST /api/admin/payments/{id}/delete (Admins) */
    public function destroyOrderPayment(Request $request, int $id): JsonResponse
    {
        $payment = Payment::findOrFail($id);
        $this->payments->deletePayment($payment, $request->user());

        return response()->json(['message' => 'Payment of '.number_format((float) $payment->amount).' UGX deleted: the balance is back on the order.']);
    }

    /** POST /api/admin/package-payments/{id}/delete (Admins) */
    public function destroyPackagePayment(Request $request, int $id): JsonResponse
    {
        $payment = PackagePayment::findOrFail($id);
        $this->payments->deletePayment($payment, $request->user());

        return response()->json(['message' => 'Payment of '.number_format((float) $payment->amount).' UGX deleted: the balance is back on the package.']);
    }

    private function correct(Request $request, Payment|PackagePayment $payment): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => ['required', Rule::in(AdminPayments::METHODS)],
            'transaction_number' => 'nullable|string|max:100',
            'details' => 'nullable|string|max:1000',
            'paid_at' => 'nullable|string|max:40',
        ]);
        $data['paid_at'] = filled($data['paid_at'] ?? null) ? OrderController::placedAt($data['paid_at'], 'paid_at') : null;

        $this->payments->updatePayment($payment, $data, $request->user());

        return response()->json(['message' => 'Payment saved.', 'data' => new AdminResource($payment->fresh())]);
    }

    public function orderPayments(Request $request)
    {
        $query = QueryBuilder::for(Payment::class)
            ->with(['order:id,order_number,charged_amount,balance_due', 'customer:id,name,phone', 'createdBy:id,name,email'])
            ->allowedFilters([
                AdminList::search(['receipt_number', 'transaction_number', 'details', 'order.order_number', 'customer.name', 'customer.phone']),
                AllowedFilter::exact('payment_method'),
                AllowedFilter::exact('order_id'),
                AllowedFilter::exact('customer', 'user_id'),
                // Payments on orders matching the dashboard's filters (its arrow to this list carries them).
                ...array_map(
                    fn (string $key) => AllowedFilter::callback($key, fn (Builder $q, $v) => $q->whereHas('order', fn (Builder $o) => $o->matching([$key => $v]))),
                    [...Order::ITEM_FILTERS, ...Order::STATUS_FILTERS],
                ),
                ...AdminList::dateRange(),
            ])
            ->allowedSorts(['id', 'receipt_number', 'amount', 'payment_method', 'created_at'])
            ->defaultSort('-created_at');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    public function showOrderPayment(int $id)
    {
        return new AdminResource(Payment::with(['order', 'customer:id,name,phone,email', 'createdBy:id,name,email', 'updatedBy:id,name,email'])->findOrFail($id));
    }

    public function packagePayments(Request $request)
    {
        $query = QueryBuilder::for(PackagePayment::class)
            ->with(['package:id,name,package_number,charged_amount,balance_due', 'customer:id,name,phone', 'createdBy:id,name,email'])
            ->allowedFilters([
                AdminList::search(['receipt_number', 'transaction_number', 'details', 'package.name', 'package.package_number', 'customer.name', 'customer.phone']),
                AllowedFilter::exact('payment_method'),
                AllowedFilter::exact('package_id'),
                AllowedFilter::exact('customer', 'user_id'),
                ...AdminList::dateRange(),
            ])
            ->allowedSorts(['id', 'receipt_number', 'amount', 'payment_method', 'created_at'])
            ->defaultSort('-created_at');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    public function showPackagePayment(int $id)
    {
        return new AdminResource(PackagePayment::with(['package', 'customer:id,name,phone,email', 'createdBy:id,name,email', 'updatedBy:id,name,email'])->findOrFail($id));
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\Order;
use App\Models\PackagePayment;
use App\Models\Payment;
use App\Support\AdminList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Money received: order payments (/api/admin/payments) and package payments
 * (/api/admin/package-payments). Read-only lists; payments are recorded on their order or package.
 */
class PaymentController extends Controller
{
    public function orderPayments(Request $request)
    {
        $query = QueryBuilder::for(Payment::class)
            ->with(['order:id,order_number,charged_amount,balance_due', 'customer:id,name,phone', 'createdBy:id,name'])
            ->allowedFilters([
                AdminList::search(['transaction_number', 'details', 'order.order_number', 'customer.name', 'customer.phone']),
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
            ->allowedSorts(['id', 'amount', 'payment_method', 'created_at'])
            ->defaultSort('-created_at');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    public function showOrderPayment(int $id)
    {
        return new AdminResource(Payment::with(['order', 'customer:id,name,phone,email', 'createdBy:id,name', 'updatedBy:id,name'])->findOrFail($id));
    }

    public function packagePayments(Request $request)
    {
        $query = QueryBuilder::for(PackagePayment::class)
            ->with(['package:id,name,package_number,charged_amount,balance_due', 'customer:id,name,phone', 'createdBy:id,name'])
            ->allowedFilters([
                AdminList::search(['transaction_number', 'details', 'package.name', 'package.package_number', 'customer.name', 'customer.phone']),
                AllowedFilter::exact('payment_method'),
                AllowedFilter::exact('package_id'),
                AllowedFilter::exact('customer', 'user_id'),
                ...AdminList::dateRange(),
            ])
            ->allowedSorts(['id', 'amount', 'payment_method', 'created_at'])
            ->defaultSort('-created_at');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    public function showPackagePayment(int $id)
    {
        return new AdminResource(PackagePayment::with(['package', 'customer:id,name,phone,email', 'createdBy:id,name', 'updatedBy:id,name'])->findOrFail($id));
    }
}

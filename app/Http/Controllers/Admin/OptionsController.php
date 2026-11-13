<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CategoryBrand;
use App\Models\ElectronicBrand;
use App\Models\ElectronicCategory;
use App\Models\ElectronicType;
use App\Models\ExploreCategory;
use App\Models\InventoryType;
use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Services\AdminPayments;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

/**
 * GET /api/admin/options: the choices every form and filter needs, in one call. The tables are
 * small (a few dozen rows), so dropdowns get them whole.
 */
class OptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $pick = fn ($model, array $extra = []) => $model::orderBy('name')->get(['id', 'name', 'status', ...$extra]);

        return response()->json([
            'product_categories' => $pick(ProductCategory::class),
            'category_brands' => $pick(CategoryBrand::class, ['product_categories_id']),
            'product_types' => $pick(ProductType::class),
            'inventory_types' => $pick(InventoryType::class),
            'electronic_categories' => $pick(ElectronicCategory::class),
            'electronic_brands' => $pick(ElectronicBrand::class, ['electronic_categories_id']),
            'electronic_types' => $pick(ElectronicType::class, ['electronic_brands_id']),
            'explore_categories' => $pick(ExploreCategory::class),
            'roles' => Role::orderBy('id')->pluck('name'),
            'order_statuses' => OrderController::STATUSES,
            'order_payment_statuses' => OrderController::PAYMENT_STATUSES,
            'order_delivery_statuses' => OrderController::DELIVERY_STATUSES,
            'package_statuses' => PackageController::STATUSES,
            'package_payment_statuses' => PackageController::PAYMENT_STATUSES,
            'package_delivery_statuses' => PackageController::DELIVERY_STATUSES,
            'payment_methods' => AdminPayments::METHODS,
            'payment_modes' => ['bnpl', 'cod'],
        ]);
    }
}

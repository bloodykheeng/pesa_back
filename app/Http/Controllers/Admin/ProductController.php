<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StoresPhotos;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\Product;
use App\Support\AdminList;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The catalogue the app sells. A product belongs to an inventory type and, depending on it, to a
 * gas/water brand and type, or to an electronic category, brand and type.
 */
class ProductController extends Controller
{
    use StoresPhotos;

    private const RELATIONS = [
        'inventoryType:id,name',
        'categoryBrand:id,name,product_categories_id',
        'productType:id,name',
        'electronicCategory:id,name',
        'electronicBrand:id,name',
        'electronicType:id,name',
    ];

    public function index(Request $request)
    {
        $query = QueryBuilder::for(Product::class)
            ->with(self::RELATIONS)
            ->allowedFilters([
                AdminList::search(['name', 'details']),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('inventory_types_id'),
                AllowedFilter::exact('product_types_id'),
                AllowedFilter::exact('category_brands_id'),
                AllowedFilter::exact('electronic_category_id'),
                AllowedFilter::exact('electronic_brand_id'),
                AllowedFilter::exact('electronic_type_id'),
                ...AdminList::dateRange(),
            ])
            ->allowedSorts(['id', 'name', 'price', 'quantity', 'status', 'created_at'])
            ->defaultSort('-id');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    public function show(int $id)
    {
        return new AdminResource(Product::with([...self::RELATIONS, 'createdBy:id,name', 'updatedBy:id,name'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(creating: true));
        $product = new Product;
        $product->forceFill($this->fields($data) + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->applyPhoto($request, $product, 'product_photos');
        $product->save();

        return response()->json(['message' => 'Product created.', 'data' => new AdminResource($product->refresh()->load(self::RELATIONS))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $data = $request->validate($this->rules(creating: false));
        $product->forceFill($this->fields($data) + ['updated_by' => $request->user()->id]);
        $this->applyPhoto($request, $product, 'product_photos');
        $product->save();

        return response()->json(['message' => 'Product saved.', 'data' => new AdminResource($product->refresh()->load(self::RELATIONS))]);
    }

    public function destroyMany(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer'])['ids'];
        $products = Product::whereIn('id', $ids)->get();

        // order_products.product_id is SET NULL on delete: past orders would lose their product.
        $onOrders = Product::whereIn('id', $ids)->whereExists(fn ($q) => $q->from('order_products')->whereColumn('order_products.product_id', 'products.id'))->pluck('name');
        if ($onOrders->isNotEmpty()) {
            return response()->json(['message' => 'These are on past orders and are kept: ' . $onOrders->implode(', ') . '. Make them inactive instead.'], 422);
        }

        try {
            $products->each(fn (Product $p) => $p->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return response()->json(['message' => 'Some of these are on orders, so they were kept. Make them inactive instead.'], 422);
            }
            throw $e;
        }
        $products->each(fn (Product $p) => $this->deletePhoto($p->photo_url));

        return response()->json(['message' => $products->count() . ' product' . ($products->count() === 1 ? '' : 's') . ' deleted.']);
    }

    private function rules(bool $creating): array
    {
        return [
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'details' => 'nullable|string',
            'inventory_types_id' => 'required|exists:inventory_types,id',
            'category_brands_id' => 'nullable|exists:category_brands,id',
            'product_types_id' => 'nullable|exists:product_types,id',
            'electronic_category_id' => 'nullable|exists:electronic_categories,id',
            'electronic_brand_id' => 'nullable|exists:electronic_brands,id',
            'electronic_type_id' => 'nullable|exists:electronic_types,id',
            // The app shows every product with its picture, so a new one needs one.
            'photo' => ($creating ? 'required|' : '') . self::PHOTO_RULE,
            'remove_photo' => 'nullable|boolean',
        ];
    }

    private function fields(array $data): array
    {
        return collect($data)->except(['photo', 'remove_photo'])->all();
    }
}

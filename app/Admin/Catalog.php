<?php

namespace App\Admin;

use App\Models\Ad;
use App\Models\CategoryBrand;
use App\Models\ElectronicBrand;
use App\Models\ElectronicCategory;
use App\Models\ElectronicType;
use App\Models\ExploreCategory;
use App\Models\ExploreCategoryBlog;
use App\Models\Faq;
use App\Models\InventoryType;
use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Models\Referal;

/**
 * The simple tables the admin edits through one controller (Admin\CatalogController), keyed by
 * their URL: /api/admin/<key>.
 *
 * model     the Eloquent model
 * label     singular name for messages
 * photo     public/ folder for its picture, or null when it has none (the folder the old
 *           controllers used, so the app's image paths stay valid)
 * search    columns filter[search] looks in
 * filters   columns filterable by exact value
 * sorts     columns the list may sort by
 * include   relations loaded on every row and on View
 * counts    relations counted on every row (how many products use this type...)
 * rules     validation for create and update
 * readonly  true when staff only view and delete (customers create them in the app)
 * dependents [table, column, label] rows that point here. The database CASCADES most of these
 *            (deleting a category would silently delete its brands and their products), so a
 *            delete is refused while any exist.
 */
class Catalog
{
    private const STATUS = 'required|in:active,inactive';

    public static function all(): array
    {
        return [
            'product-categories' => [
                'model' => ProductCategory::class, 'label' => 'product category', 'photo' => 'category_photos',
                'search' => ['name', 'code', 'details'], 'filters' => ['status'], 'sorts' => ['name', 'code', 'status'],
                'include' => [], 'counts' => ['brands'],
                'dependents' => [['category_brands', 'product_categories_id', 'brands']],
                'rules' => ['name' => 'required|string|max:255', 'code' => 'nullable|string|max:50', 'status' => self::STATUS, 'details' => 'nullable|string'],
            ],
            'category-brands' => [
                'model' => CategoryBrand::class, 'label' => 'brand', 'photo' => 'brand_photos',
                'search' => ['name', 'code', 'details'], 'filters' => ['status', 'product_categories_id'], 'sorts' => ['name', 'code', 'status'],
                'include' => ['productCategory:id,name'], 'counts' => ['products'],
                'dependents' => [['products', 'category_brands_id', 'products']],
                'rules' => ['name' => 'required|string|max:255', 'code' => 'nullable|string|max:50', 'status' => self::STATUS, 'details' => 'nullable|string', 'product_categories_id' => 'required|exists:product_categories,id'],
            ],
            'product-types' => [
                'model' => ProductType::class, 'label' => 'product type', 'photo' => 'type_photos',
                'search' => ['name', 'code', 'details'], 'filters' => ['status'], 'sorts' => ['name', 'code', 'status'],
                'include' => [], 'counts' => ['products'],
                'dependents' => [['products', 'product_types_id', 'products']],
                'rules' => ['name' => 'required|string|max:255', 'code' => 'nullable|string|max:50', 'status' => self::STATUS, 'details' => 'nullable|string'],
            ],
            'inventory-types' => [
                'model' => InventoryType::class, 'label' => 'inventory type', 'photo' => 'inventory_type_photos',
                'search' => ['name', 'code', 'details'], 'filters' => ['status'], 'sorts' => ['name', 'code', 'status'],
                'include' => [], 'counts' => [],
                'dependents' => [['products', 'inventory_types_id', 'products']],
                'rules' => ['name' => 'required|string|max:255', 'code' => 'nullable|string|max:50', 'status' => self::STATUS, 'details' => 'nullable|string'],
            ],
            'electronic-categories' => [
                'model' => ElectronicCategory::class, 'label' => 'fuel category', 'photo' => 'category_photos',
                'search' => ['name', 'code', 'details'], 'filters' => ['status'], 'sorts' => ['name', 'code', 'status'],
                'include' => [], 'counts' => ['brands'],
                'dependents' => [['electronic_brands', 'electronic_categories_id', 'brands'], ['products', 'electronic_category_id', 'products']],
                'rules' => ['name' => 'required|string|max:255', 'code' => 'nullable|string|max:50', 'status' => self::STATUS, 'details' => 'nullable|string'],
            ],
            'electronic-brands' => [
                'model' => ElectronicBrand::class, 'label' => 'fuel brand', 'photo' => 'brand_photos',
                'search' => ['name', 'code', 'details'], 'filters' => ['status', 'electronic_categories_id'], 'sorts' => ['name', 'code', 'status'],
                'include' => ['electronicCategory:id,name'], 'counts' => ['types', 'products'],
                'dependents' => [['electronic_types', 'electronic_brands_id', 'types'], ['products', 'electronic_brand_id', 'products']],
                'rules' => ['name' => 'required|string|max:255', 'code' => 'nullable|string|max:50', 'status' => self::STATUS, 'details' => 'nullable|string', 'electronic_categories_id' => 'required|exists:electronic_categories,id'],
            ],
            'electronic-types' => [
                'model' => ElectronicType::class, 'label' => 'fuel type', 'photo' => 'type_photos',
                'search' => ['name', 'code', 'details'], 'filters' => ['status', 'electronic_brands_id'], 'sorts' => ['name', 'code', 'status'],
                'include' => ['electronicBrand:id,name,electronic_categories_id'], 'counts' => ['products'],
                'dependents' => [['products', 'electronic_type_id', 'products']],
                'rules' => ['name' => 'required|string|max:255', 'code' => 'nullable|string|max:50', 'status' => self::STATUS, 'details' => 'nullable|string', 'electronic_brands_id' => 'required|exists:electronic_brands,id'],
            ],
            'explore-categories' => [
                'model' => ExploreCategory::class, 'label' => 'explore category', 'photo' => null,
                'search' => ['name'], 'filters' => ['status'], 'sorts' => ['name', 'status'],
                'include' => [], 'counts' => ['blogs'],
                'dependents' => [['explore_category_blogs', 'explore_categories_id', 'articles']],
                'rules' => ['name' => 'required|string|max:255', 'status' => self::STATUS],
            ],
            'explore-category-blogs' => [
                'model' => ExploreCategoryBlog::class, 'label' => 'article', 'photo' => 'blog_photos',
                'search' => ['name', 'details'], 'filters' => ['status', 'explore_categories_id'], 'sorts' => ['name', 'status'],
                'include' => ['exploreCategory:id,name'], 'counts' => [],
                'rules' => ['name' => 'required|string|max:255', 'status' => self::STATUS, 'details' => 'nullable|string', 'explore_categories_id' => 'required|exists:explore_categories,id'],
            ],
            'ads' => [
                'model' => Ad::class, 'label' => 'advert', 'photo' => 'ad_photos',
                'search' => ['title', 'details'], 'filters' => ['status'], 'sorts' => ['title', 'status', 'start_date', 'end_date'],
                'include' => [], 'counts' => [],
                'rules' => ['title' => 'required|string|max:255', 'details' => 'nullable|string', 'status' => 'required|in:active,inactive,pending', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date'],
            ],
            'faqs' => [
                'model' => Faq::class, 'label' => 'FAQ', 'photo' => null,
                'search' => ['question', 'answer'], 'filters' => ['status'], 'sorts' => ['status'],
                'include' => [], 'counts' => [],
                'rules' => ['question' => 'required|string', 'answer' => 'required|string', 'status' => self::STATUS],
            ],
            'referrals' => [
                'model' => Referal::class, 'label' => 'referral', 'photo' => null,
                'search' => ['name', 'createdBy.name', 'createdBy.phone'], 'filters' => ['created_by'], 'sorts' => ['name'],
                'include' => ['createdBy:id,name,email,phone'], 'counts' => [],
                'rules' => ['name' => 'required|string|max:255'],
                'readonly' => true,
            ],
        ];
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? abort(404);
    }
}

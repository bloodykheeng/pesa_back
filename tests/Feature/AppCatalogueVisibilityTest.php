<?php

namespace Tests\Feature;

use App\Models\CategoryBrand;
use App\Models\ElectronicBrand;
use App\Models\ElectronicCategory;
use App\Models\ElectronicType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the mobile app sees of the catalogue (App\Traits\ShownInApp, docs/catalogue-visibility.md): Active records whose
 * parents are all Active. Inactive is how something on past orders (it cannot be deleted) leaves the app. Each endpoint
 * here is one the app calls (nginx log, 18 Nov 2026); each test fails without the scope.
 */
class AppCatalogueVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function names(array $rows, string $key = 'name'): array
    {
        $names = array_column($rows, $key);
        sort($names);

        return $names;
    }

    /** @return array{0: ProductCategory, 1: CategoryBrand, 2: CategoryBrand, 3: ProductType} */
    private function gasCatalogue(): array
    {
        $gas = ProductCategory::forceCreate(['name' => 'Elevate Pesa gas', 'code' => 'EPG', 'status' => 'active']);
        $sixKg = CategoryBrand::forceCreate(['name' => 'Elevate Pesa 6 kg', 'code' => 'EP6', 'status' => 'active', 'product_categories_id' => $gas->id]);
        $water = CategoryBrand::forceCreate(['name' => 'Tiva water', 'code' => 'TIVA', 'status' => 'active', 'product_categories_id' => $gas->id]);
        $refill = ProductType::forceCreate(['name' => 'Refilling', 'code' => 'REF', 'status' => 'active']);

        return [$gas, $sixKg, $water, $refill];
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::forceCreate($attributes + ['name' => $name, 'price' => 48000, 'quantity' => 5, 'status' => 'active']);
    }

    public function test_an_inactive_product_leaves_the_app_and_an_active_one_stays(): void
    {
        [, $sixKg, , $refill] = $this->gasCatalogue();
        $this->product('6 kg refill', ['category_brands_id' => $sixKg->id, 'product_types_id' => $refill->id]);
        $this->product('Water pump', ['category_brands_id' => $sixKg->id, 'product_types_id' => $refill->id, 'status' => 'inactive']);

        $types = $this->getJson('/api/app-product-types')->assertOk()->json('data');

        $this->assertSame(['Refilling'], $this->names($types));
        $this->assertSame(['6 kg refill'], $this->names($types[0]['products']));
    }

    public function test_an_inactive_brand_leaves_the_app_with_its_products(): void
    {
        [, $sixKg, $water, $refill] = $this->gasCatalogue();
        $this->product('6 kg refill', ['category_brands_id' => $sixKg->id, 'product_types_id' => $refill->id]);
        $this->product('Tiva 20 L', ['category_brands_id' => $water->id, 'product_types_id' => $refill->id]);
        $water->forceFill(['status' => 'inactive'])->save();

        $categories = $this->getJson('/api/app-product-categories')->assertOk()->json('data');
        $this->assertSame(['Elevate Pesa 6 kg'], $this->names($categories[0]['brands']));

        // The product itself is still Active: its brand hides it.
        $products = $this->getJson('/api/app-product-types')->json('data.0.products');
        $this->assertSame(['6 kg refill'], $this->names($products));
    }

    public function test_an_inactive_category_hides_itself_its_brands_and_their_products(): void
    {
        [$gas, $sixKg, , $refill] = $this->gasCatalogue();
        $this->product('6 kg refill', ['category_brands_id' => $sixKg->id, 'product_types_id' => $refill->id]);
        $gas->forceFill(['status' => 'inactive'])->save();

        $this->assertSame([], $this->getJson('/api/app-product-categories')->json('data'));
        $this->assertSame([], $this->getJson('/api/app-product-types')->json('data.0.products'));
    }

    public function test_fuel_brands_types_and_products_follow_the_same_rule_and_staff_still_see_everything(): void
    {
        $fuel = ElectronicCategory::forceCreate(['name' => 'Fuel', 'code' => 'FUEL', 'status' => 'active']);
        $shell = ElectronicBrand::forceCreate(['name' => 'Shell Stations', 'code' => 'SH', 'status' => 'active', 'electronic_categories_id' => $fuel->id]);
        $old = ElectronicBrand::forceCreate(['name' => 'Tecno', 'code' => 'TEC', 'status' => 'inactive', 'electronic_categories_id' => $fuel->id]);
        $petrol = ElectronicType::forceCreate(['name' => 'Petrol', 'code' => 'PET', 'status' => 'active', 'electronic_brands_id' => $shell->id]);
        $pop = ElectronicType::forceCreate(['name' => 'Pop 8', 'code' => 'POP', 'status' => 'active', 'electronic_brands_id' => $old->id]);
        $this->product('Petrol 1 L', ['electronic_category_id' => $fuel->id, 'electronic_brand_id' => $shell->id, 'electronic_type_id' => $petrol->id]);
        $this->product('Tecno Pop 8', ['electronic_category_id' => $fuel->id, 'electronic_brand_id' => $old->id, 'electronic_type_id' => $pop->id]);

        Sanctum::actingAs($this->customer());
        $this->assertSame(['Fuel'], $this->names($this->getJson('/api/app-get-electronic-categories')->assertOk()->json('data')));
        $this->assertSame(['Shell Stations'], $this->names($this->getJson('/api/app-get-electronic-brands')->assertOk()->json('data')));
        $this->assertSame(['Petrol'], $this->names($this->getJson('/api/app-get-electronic-types')->assertOk()->json('data')));
        $this->assertSame(['Petrol 1 L'], $this->names($this->getJson('/api/products')->assertOk()->json('data')));

        // The old staff route keeps the whole catalogue.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin());
        $this->assertSame(['Petrol 1 L', 'Tecno Pop 8'], $this->names($this->getJson('/api/products')->assertOk()->json('data')));
    }
}

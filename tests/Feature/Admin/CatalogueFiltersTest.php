<?php

namespace Tests\Feature\Admin;

use App\Models\CategoryBrand;
use App\Models\ElectronicBrand;
use App\Models\ElectronicCategory;
use App\Models\InventoryType;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductType;
use Laravel\Sanctum\Sanctum;

/** The catalogue cascades top down in every filter (the old admin's way): inventory type › category › brand › product. */
class CatalogueFiltersTest extends AdminTestCase
{
    public function test_orders_narrow_by_every_level_and_each_inventory_type_knows_its_side(): void
    {
        $gasType = InventoryType::forceCreate(['name' => 'Gas & Water', 'status' => 'active']);
        $elecType = InventoryType::forceCreate(['name' => 'Gadgets', 'status' => 'active']);
        $gas = ProductCategory::forceCreate(['name' => 'Gas', 'code' => '01', 'status' => 'active']);
        $shell = CategoryBrand::forceCreate(['name' => 'Shell', 'code' => 'SH', 'status' => 'active', 'product_categories_id' => $gas->id]);
        $total = CategoryBrand::forceCreate(['name' => 'Total', 'code' => 'TO', 'status' => 'active', 'product_categories_id' => $gas->id]);
        $refill = ProductType::forceCreate(['name' => 'Refilling', 'code' => 'RF', 'status' => 'active']);
        $phones = ElectronicCategory::forceCreate(['name' => 'Phones', 'status' => 'active']);
        $tecno = ElectronicBrand::forceCreate(['name' => 'Tecno', 'status' => 'active', 'electronic_categories_id' => $phones->id]);

        $shellGas = Product::forceCreate(['name' => 'Shell 12kg', 'price' => 1, 'quantity' => 1, 'status' => 'active', 'inventory_types_id' => $gasType->id, 'category_brands_id' => $shell->id, 'product_types_id' => $refill->id]);
        $totalGas = Product::forceCreate(['name' => 'Total 6kg', 'price' => 1, 'quantity' => 1, 'status' => 'active', 'inventory_types_id' => $gasType->id, 'category_brands_id' => $total->id]);
        $phone = Product::forceCreate(['name' => 'Tecno Pop', 'price' => 1, 'quantity' => 1, 'status' => 'active', 'inventory_types_id' => $elecType->id, 'electronic_category_id' => $phones->id, 'electronic_brand_id' => $tecno->id]);

        $customer = $this->customer();
        foreach ([$shellGas, $totalGas, $phone] as $p) {
            $order = $this->order($customer);
            OrderProduct::forceCreate(['order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name, 'quantity' => 1, 'price' => 1]);
        }
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/orders?filter[inventory_type]='.$gasType->id)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/admin/orders?filter[category_brand]='.$shell->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/orders?filter[product_type]='.$refill->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/orders?filter[electronic_brand]='.$tecno->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/dashboard?category_brand='.$total->id)->assertOk();

        // The products a cascaded picker offers: those of a gas & water category's brands.
        $this->getJson('/api/admin/products?filter[product_category]='.$gas->id)->assertJsonPath('meta.total', 2);

        // The side, read from each type's products (the name "Gadgets" says nothing).
        $sides = collect($this->getJson('/api/admin/options')->json('inventory_types'))->pluck('side', 'name');
        $this->assertSame('gas_water', $sides['Gas & Water']);
        $this->assertSame('electronics', $sides['Gadgets']);
    }
}

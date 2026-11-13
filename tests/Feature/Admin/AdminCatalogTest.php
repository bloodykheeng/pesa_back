<?php

namespace Tests\Feature\Admin;

use App\Models\InventoryType;
use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

class AdminCatalogTest extends AdminTestCase
{
    public function test_a_simple_table_is_created_viewed_edited_and_deleted(): void
    {
        Sanctum::actingAs($this->admin());

        $id = $this->post('/api/admin/product-types', [
            'name' => 'Cylinder', 'code' => 'CY', 'status' => 'active',
            'photo' => UploadedFile::fake()->image('cyl.png', 300, 300),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $photo = ProductType::find($id)->photo_url;
        $this->assertMatchesRegularExpression('#^/type_photos/\d+_[a-z0-9]{8}\.png$#', $photo);
        $this->assertFileExists(public_path(ltrim($photo, '/')));

        $this->getJson("/api/admin/product-types/{$id}")->assertOk()->assertJsonPath('data.name', 'Cylinder')->assertJsonPath('data.products_count', 0);
        $this->getJson('/api/admin/product-types?filter[search]=cyl')->assertJsonPath('meta.total', 1);

        // A save without a photo keeps the picture; remove_photo clears it and deletes the file.
        $this->postJson("/api/admin/product-types/{$id}", ['name' => 'Gas cylinder', 'status' => 'inactive'])->assertOk();
        $this->assertSame($photo, ProductType::find($id)->photo_url);
        $this->postJson("/api/admin/product-types/{$id}", ['name' => 'Gas cylinder', 'status' => 'inactive', 'remove_photo' => true])->assertOk();
        $this->assertNull(ProductType::find($id)->photo_url);
        $this->assertFileDoesNotExist(public_path(ltrim($photo, '/')));

        $this->postJson('/api/admin/product-types/bulk-delete', ['ids' => [$id]])->assertOk();
        $this->assertDatabaseMissing('product_types', ['id' => $id]);
    }

    public function test_validation_names_the_field(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/category-brands', ['name' => 'X', 'status' => 'active'])
            ->assertStatus(422)->assertJsonValidationErrors('product_categories_id');
        $this->postJson('/api/admin/ads', ['title' => 'Sale', 'status' => 'active', 'start_date' => '2026-02-01', 'end_date' => '2026-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_a_row_in_use_is_kept_with_a_reason(): void
    {
        $admin = $this->admin();
        $type = InventoryType::forceCreate(['name' => 'Gas', 'status' => 'active', 'created_by' => $admin->id, 'updated_by' => $admin->id]);
        Product::forceCreate(['name' => 'Oryx 6kg', 'status' => 'active', 'price' => 1, 'quantity' => 1, 'inventory_types_id' => $type->id, 'created_by' => $admin->id, 'updated_by' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/inventory-types/bulk-delete', ['ids' => [$type->id]])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Still used by 1 products'));
        $this->assertDatabaseHas('inventory_types', ['id' => $type->id]);
    }

    public function test_referrals_are_read_only_and_unknown_tables_404(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/referrals', ['name' => 'X'])->assertStatus(405);
        $this->getJson('/api/admin/referrals')->assertOk();
        $this->getJson('/api/admin/not-a-table')->assertNotFound();
    }

    public function test_products_need_a_photo_when_created_and_filter_by_type(): void
    {
        $admin = $this->admin();
        $gas = InventoryType::forceCreate(['name' => 'Gas', 'status' => 'active', 'created_by' => $admin->id, 'updated_by' => $admin->id]);
        Sanctum::actingAs($admin);
        $fields = ['name' => 'Oryx 6kg', 'status' => 'active', 'price' => 85000, 'quantity' => 10, 'inventory_types_id' => $gas->id];

        $this->postJson('/api/admin/products', $fields)->assertStatus(422)->assertJsonValidationErrors('photo');
        $id = $this->post('/api/admin/products', $fields + ['photo' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.inventory_type.name', 'Gas')->json('data.id');

        $this->assertStringStartsWith('/product_photos/', Product::find($id)->photo_url);
        $this->getJson('/api/admin/products?filter[inventory_types_id]=' . $gas->id)->assertJsonPath('meta.total', 1);
        $this->postJson("/api/admin/products/{$id}", array_merge($fields, ['price' => 90000]))->assertOk()->assertJsonPath('data.price', '90000.00');
    }
}

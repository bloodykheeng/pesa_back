<?php

namespace Tests\Feature\Admin;

use App\Models\CategoryBrand;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;

/**
 * The delete rules of 2026_11_15_120000_tighten_foreign_key_delete_rules (docs/foreign-key-conventions.md): a delete
 * never silently takes history with it. Each test fails on the old rules (CASCADE / SET NULL).
 */
class ForeignKeyRulesTest extends AdminTestCase
{
    private function refused(callable $delete): void
    {
        try {
            $delete();
            $this->fail('The database allowed a delete it must refuse.');
        } catch (QueryException $e) {
            $this->assertSame(1451, $e->errorInfo[1] ?? null, 'Refused for being referenced (1451).');
        }
    }

    public function test_a_customer_with_orders_is_kept_with_them(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);

        $this->refused(fn () => $customer->delete());

        $this->assertModelExists($order);
    }

    public function test_an_order_with_payments_keeps_them_and_its_lines_go_with_it_when_it_has_none(): void
    {
        $customer = $this->customer();
        $paid = $this->order($customer);
        Payment::forceCreate(['order_id' => $paid->id, 'user_id' => $customer->id, 'amount' => 10000, 'payment_method' => 'cash']);

        $this->refused(fn () => $paid->delete());

        // Composition: an unpaid order's lines exist only to describe it.
        $unpaid = $this->order($customer);
        $line = OrderProduct::forceCreate(['order_id' => $unpaid->id, 'name' => '6 kg refill', 'quantity' => 1, 'price' => 48000]);
        $unpaid->delete();
        $this->assertModelMissing($line);
    }

    public function test_the_catalogue_keeps_products_and_an_old_order_keeps_its_line_when_a_product_goes(): void
    {
        $gas = ProductCategory::forceCreate(['name' => 'Gas', 'code' => 'GAS', 'status' => 'active']);
        $brand = CategoryBrand::forceCreate(['name' => 'Elevate Pesa', 'code' => 'EP', 'status' => 'active', 'product_categories_id' => $gas->id]);
        $product = Product::forceCreate(['name' => '6 kg cylinder', 'price' => 130000, 'quantity' => 5, 'status' => 'active', 'category_brands_id' => $brand->id]);

        $this->refused(fn () => $gas->delete());
        $this->refused(fn () => $brand->delete());

        // SET NULL by design: the line carries the product's name and price.
        $order = $this->order($this->customer());
        $line = OrderProduct::forceCreate(['order_id' => $order->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'price' => 130000]);
        $product->delete();
        $line->refresh();
        $this->assertNull($line->product_id);
        $this->assertSame('6 kg cylinder', $line->name);
    }

    public function test_a_staff_member_who_made_catalogue_records_is_kept_and_the_admin_says_why(): void
    {
        $boss = $this->admin();
        $editor = $this->editor();
        ProductCategory::forceCreate(['name' => 'Fuel', 'code' => 'FUEL', 'status' => 'active', 'created_by' => $editor->id]);

        Sanctum::actingAs($boss);
        $this->postJson('/api/admin/users/bulk-delete', ['ids' => [$editor->id]])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Make them inactive instead'));

        $this->assertModelExists($editor);
        $this->assertSame(['Editor'], $editor->fresh()->getRoleNames()->all(), 'The refused batch rolled back (roles kept).');
    }

    public function test_any_refused_delete_reads_as_409_not_500(): void
    {
        $customer = $this->customer();
        $this->order($customer);
        Sanctum::actingAs($this->admin());

        // The old public API's delete has no guard of its own: the global handler answers.
        $this->deleteJson("/api/users/{$customer->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'still used by other records'));

        $this->assertModelExists($customer);
        $this->assertInstanceOf(User::class, User::find($customer->id));
        $this->assertSame(1, Order::where('created_by', $customer->id)->count());
    }
}

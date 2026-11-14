<?php

namespace Tests\Feature\Admin;

use App\Models\CategoryBrand;
use App\Models\OrderProduct;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Audit\Audit;
use App\Support\Audit\Device;
use Laravel\Sanctum\Sanctum;

/**
 * The dashboard's order filters, its figures against the lists its arrows open, an Editor opening an order's
 * customer, and what the audit trail list says about each entry.
 */
class DashboardFiltersTest extends AdminTestCase
{
    public function test_a_category_filter_narrows_the_figures_and_the_lists_its_arrows_open_to_the_same_rows(): void
    {
        $gas = ProductCategory::forceCreate(['name' => 'Gas', 'code' => 'GAS', 'status' => 'active']);
        $water = ProductCategory::forceCreate(['name' => 'Water', 'code' => 'WTR', 'status' => 'active']);
        $shell = CategoryBrand::forceCreate(['name' => 'Shell', 'code' => 'SHL', 'status' => 'active', 'product_categories_id' => $gas->id]);
        $rwenzori = CategoryBrand::forceCreate(['name' => 'Rwenzori', 'code' => 'RWZ', 'status' => 'active', 'product_categories_id' => $water->id]);
        $cylinder = Product::forceCreate(['name' => 'Shell 12kg', 'price' => 110000, 'quantity' => 5, 'status' => 'active', 'category_brands_id' => $shell->id]);
        $bottle = Product::forceCreate(['name' => 'Rwenzori 20L', 'price' => 10000, 'quantity' => 5, 'status' => 'active', 'category_brands_id' => $rwenzori->id]);

        $customer = $this->customer();
        $line = fn ($order, $product) => OrderProduct::forceCreate(['order_id' => $order->id, 'product_id' => $product->id, 'name' => $product->name, 'price' => $product->price, 'quantity' => 1]);
        $gasOrder = $this->order($customer, ['charged_amount' => 110000, 'balance_due' => 60000, 'amount_paid' => 50000]);
        $line($gasOrder, $cylinder);
        $mixed = $this->order($customer, ['charged_amount' => 120000, 'balance_due' => 120000]);
        $line($mixed, $cylinder);
        $line($mixed, $bottle);
        $waterOrder = $this->order($customer, ['charged_amount' => 10000, 'balance_due' => 10000]);
        $line($waterOrder, $bottle);
        Payment::forceCreate(['order_id' => $gasOrder->id, 'user_id' => $customer->id, 'amount' => 50000, 'payment_method' => 'cash']);
        Payment::forceCreate(['order_id' => $waterOrder->id, 'user_id' => $customer->id, 'amount' => 5000, 'payment_method' => 'cash']);

        Sanctum::actingAs($this->admin());

        $all = $this->getJson('/api/admin/dashboard')->assertOk()->json();
        $this->assertSame(3, $all['orders']['count']);
        $this->assertFalse($all['filtered']);

        $d = $this->getJson("/api/admin/dashboard?product_category={$gas->id}")->assertOk()->json();
        $this->assertTrue($d['filtered']);
        $this->assertSame(2, $d['orders']['count'], 'an order matches when any of its items does');
        $this->assertSame(1, $d['payments']['count']);
        $this->assertSame(['Shell 12kg'], array_column($d['top_products'], 'name'), 'top products keep to the category');

        // The arrows' lists, with the same filters, count the same rows.
        $this->assertSame(2, $this->getJson("/api/admin/orders?filter[product_category]={$gas->id}")->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson("/api/admin/payments?filter[product_category]={$gas->id}")->assertOk()->json('meta.total'));

        $byProduct = $this->getJson("/api/admin/dashboard?product={$bottle->id}")->json();
        $this->assertSame(2, $byProduct['orders']['count']);
        $this->assertSame(2, $this->getJson("/api/admin/orders?filter[product]={$bottle->id}")->json('meta.total'));
    }

    public function test_an_editor_opens_an_orders_customer_but_never_a_staff_account(): void
    {
        $customer = $this->customer(['name' => 'Jane Shopper']);
        $this->order($customer);
        $admin = $this->admin();
        Sanctum::actingAs($this->editor());

        $this->getJson("/api/admin/users/{$customer->id}")->assertOk()->assertJsonPath('data.name', 'Jane Shopper')->assertJsonPath('data.orders_count', 1);
        $this->getJson("/api/admin/users/{$admin->id}")->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_the_audit_list_says_what_changed_and_from_which_device(): void
    {
        $category = ProductCategory::forceCreate(['name' => 'Gas', 'code' => 'GAS', 'status' => 'active']);
        Audit::flush();
        Sanctum::actingAs($this->admin());

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; SM-A145F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36')
            ->post("/api/admin/product-categories/{$category->id}", ['name' => 'Cooking gas', 'code' => 'GAS', 'status' => 'inactive'], ['Accept' => 'application/json'])
            ->assertOk();
        Audit::flush();

        $row = collect($this->getJson('/api/admin/audit-trail?filter[kind]=changes')->assertOk()->json('data'))->firstWhere('event', 'updated');
        $this->assertSame('Chrome 130 on Android 14', $row['device']);
        $this->assertContains(['label' => 'Name', 'old' => 'Gas', 'new' => 'Cooking gas', 'secret' => false], $row['changes_preview']);

        $signIns = $this->getJson('/api/admin/audit-trail?filter[kind]=sign_ins')->assertOk()->json('data');
        $this->assertSame([], array_values(array_filter($signIns, fn ($r) => ! in_array($r['event'], ['signed_in', 'signed_out', 'sign_in_failed'], true))));
    }

    public function test_devices_are_named_as_people_say_them(): void
    {
        $this->assertSame('The app on Android', Device::describe('okhttp/4.9.2')['summary']);
        $this->assertSame('Safari 17 on iPhone (iOS 17)', Device::describe('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1')['summary']);
        $this->assertSame('Edge 130 on Windows', Device::describe('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0')['summary']);
        $this->assertSame('Computer', Device::describe('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15')['kind']);
        $this->assertSame('Unknown device', Device::describe(null)['summary']);
    }
}

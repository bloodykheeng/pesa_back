<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use Laravel\Sanctum\Sanctum;

/** Orders, packages and payments staff type in from the dashboard (re-entering what the Hostinger loss took). */
class EnteredByStaffTest extends AdminTestCase
{
    private function product(string $name, int $price, int $stock = 10): Product
    {
        return Product::forceCreate(['name' => $name, 'price' => $price, 'quantity' => $stock, 'status' => 'active']);
    }

    public function test_an_editor_enters_a_back_dated_order_for_a_customer(): void
    {
        $customer = $this->customer(['name' => 'Jane Nakato', 'device_token' => 'phone-1']);
        $gas = $this->product('Shell 12kg', 90000);
        $water = $this->product('Elevate water', 10000);
        Sanctum::actingAs($editor = $this->editor());

        $id = $this->postJson('/api/admin/orders', [
            'customer_id' => $customer->id,
            // 3 pm Kampala wall-clock, as the picker sends it (an instant).
            'placed_at' => '2026-10-02T12:00:00.000Z',
            'address' => 'Ntinda',
            'payment_mode' => 'cod',
            'delivery_status' => 'delivered',
            'items' => [
                ['product_id' => $gas->id, 'quantity' => 2, 'price' => 85000],
                ['product_id' => $water->id, 'quantity' => 1, 'price' => 10000],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.created_by.name', 'Jane Nakato')
            ->assertJsonPath('data.entered_by.name', $editor->name)
            ->json('data.id');

        $order = Order::with('orderProducts')->find($id);
        $this->assertSame('2026-10-02 12:00:00', $order->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('180000.00', $order->charged_amount);
        $this->assertSame('180000.00', $order->balance_due);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('delivered', $order->delivery_status);
        $this->assertSame(['Shell 12kg', 'Elevate water'], $order->orderProducts->pluck('name')->all());
        $this->assertSame(8, $gas->fresh()->quantity);
        $this->assertSame(9, $water->fresh()->quantity);
        $this->assertSame([], self::$pushes, 'staff entered it: no push to the customer');

        // It is in the list and the audit trail says who typed it in.
        $this->getJson('/api/admin/orders?filter[customer]='.$customer->id)->assertJsonPath('data.0.entered_by.name', $editor->name);
    }

    public function test_stock_can_be_left_alone_and_a_bare_day_is_its_first_kampala_moment(): void
    {
        $gas = $this->product('Shell 12kg', 90000, 5);
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/admin/orders', [
            'customer_id' => $this->customer()->id, 'placed_at' => '2026-10-02', 'address' => 'Ntinda', 'payment_mode' => 'cod',
            'deduct_stock' => false, 'items' => [['product_id' => $gas->id, 'quantity' => 2, 'price' => 90000]],
        ])->assertCreated()->json('data.id');

        $this->assertSame('2026-10-01 21:00:00', Order::find($id)->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(5, $gas->fresh()->quantity);
    }

    public function test_the_form_is_checked(): void
    {
        $gas = $this->product('Shell 12kg', 90000);
        $customer = $this->customer();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/orders', ['customer_id' => $customer->id, 'address' => 'Ntinda', 'payment_mode' => 'cod', 'items' => []])
            ->assertStatus(422)->assertJsonPath('errors.items.0', 'Add at least one item.');
        $this->postJson('/api/admin/orders', [
            'customer_id' => $customer->id, 'address' => 'Ntinda', 'payment_mode' => 'cod', 'placed_at' => now()->addDay()->toJSON(),
            'items' => [['product_id' => $gas->id, 'quantity' => 1, 'price' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('placed_at');
        $this->postJson('/api/admin/orders', [
            'customer_id' => $customer->id, 'address' => 'Ntinda', 'payment_mode' => 'cod',
            'items' => [['product_id' => $gas->id, 'quantity' => 1, 'price' => 1], ['product_id' => $gas->id, 'quantity' => 1, 'price' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.1.product_id');

        // The app's BNPL rule: one unpaid BNPL order at a time.
        $this->order($customer, ['payment_mode' => 'bnpl']);
        $this->postJson('/api/admin/orders', [
            'customer_id' => $customer->id, 'address' => 'Ntinda', 'payment_mode' => 'bnpl',
            'items' => [['product_id' => $gas->id, 'quantity' => 1, 'price' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('payment_mode');
        $this->assertSame(1, Order::count());
    }

    public function test_an_editor_enters_a_package(): void
    {
        $customer = $this->customer(['name' => 'Paul', 'device_token' => 'phone-2']);
        Sanctum::actingAs($editor = $this->editor());

        $id = $this->postJson('/api/admin/packages', [
            'customer_id' => $customer->id, 'placed_at' => '2026-09-15T07:30:00.000Z',
            'name' => 'Documents', 'pickup' => 'Ntinda', 'destination' => 'Entebbe', 'charged_amount' => 15000,
        ])->assertCreated()->assertJsonPath('data.entered_by.name', $editor->name)->json('data.id');

        $package = Package::find($id);
        $this->assertSame($customer->id, $package->created_by);
        $this->assertSame('2026-09-15 07:30:00', $package->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('15000.00', $package->balance_due);
        $this->assertSame([], self::$pushes);
    }

    public function test_a_payment_for_an_earlier_date_is_dated_then_and_sends_no_push(): void
    {
        $customer = $this->customer(['device_token' => 'phone-1']);
        $order = $this->order($customer, ['created_at' => '2026-10-02 12:00:00']);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1000, 'payment_method' => 'cash', 'paid_at' => '2026-10-01T09:00:00.000Z'])
            ->assertStatus(422)->assertJsonPath('errors.paid_at.0', 'Before the order was placed (2 Oct 2026, 3:00 pm): a payment comes after it.');

        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1000, 'payment_method' => 'cash', 'paid_at' => '2026-10-03T09:00:00.000Z'])
            ->assertCreated();
        $this->assertSame('2026-10-03 09:00:00', Payment::first()->created_at->format('Y-m-d H:i:s'));
        $this->assertSame([], self::$pushes);

        // Without a date it is today's payment, and the customer is told, as before.
        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1000, 'payment_method' => 'cash'])->assertCreated();
        $this->assertCount(1, self::$pushes);
    }

    public function test_editors_can_search_customers_but_only_see_name_phone_and_email(): void
    {
        $this->customer(['name' => 'Jane Nakato', 'phone' => '256700000001', 'nin' => 'CM123']);
        $this->customer(['name' => 'Paul Okello']);
        Sanctum::actingAs($this->editor());

        $this->getJson('/api/admin/customers?filter[search]=Nakato')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.phone', '256700000001')
            ->assertJsonMissingPath('data.0.nin');
        $this->getJson('/api/admin/users')->assertForbidden();
    }
}

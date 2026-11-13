<?php

namespace Tests\Feature\Admin;

use Laravel\Sanctum\Sanctum;

class AdminOrdersTest extends AdminTestCase
{
    public function test_the_list_pages_filters_searches_and_sorts(): void
    {
        $jane = $this->customer(['name' => 'Jane Nakato', 'phone' => '256700000001']);
        $paul = $this->customer(['name' => 'Paul Okello']);
        foreach (range(1, 6) as $i) {
            $this->order($jane, ['charged_amount' => $i * 1000, 'balance_due' => $i * 1000]);
        }
        $this->order($paul, ['status' => 'cancelled', 'delivery_status' => 'cancelled']);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/orders')
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 7)->assertJsonPath('meta.per_page', 5);

        $this->getJson('/api/admin/orders?filter[search]=Nakato')->assertJsonPath('meta.total', 6);
        $this->getJson('/api/admin/orders?filter[search]=256700000001')->assertJsonPath('meta.total', 6);
        $this->getJson('/api/admin/orders?filter[status]=cancelled')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/orders?filter[customer]=' . $paul->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/orders?filter[has_balance]=1')->assertJsonPath('meta.total', 6);
        $this->getJson('/api/admin/orders?sort=-charged_amount&per_page=1')->assertJsonPath('data.0.charged_amount', '50000.00');
        $this->getJson('/api/admin/orders?sort=charged_amount&per_page=1')->assertJsonPath('data.0.charged_amount', '1000.00');
        $this->getJson('/api/admin/orders?per_page=1')->assertJsonPath('data.0.created_by.name', fn ($n) => in_array($n, ['Jane Nakato', 'Paul Okello']));
    }

    public function test_the_date_filter_uses_kampala_days(): void
    {
        $c = $this->customer();
        // 22:30 UTC on 31 Dec is 01:30 on 1 Jan in Kampala.
        $this->order($c, ['created_at' => '2025-12-31 22:30:00']);
        $this->order($c, ['created_at' => '2025-12-31 20:00:00']);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/orders?filter[from]=2026-01-01&filter[to]=2026-01-01')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/orders?filter[from]=2025-12-31&filter[to]=2025-12-31')->assertJsonPath('meta.total', 1);
    }

    public function test_recording_payments_updates_the_balance_and_completes_the_order(): void
    {
        $customer = $this->customer(['device_token' => 'phone-1']);
        $order = $this->order($customer);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 20000, 'payment_method' => 'mtn_mobile_money', 'transaction_number' => 'MP1'])
            ->assertCreated()->assertJsonPath('data.balance_due', '30000.00');

        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 40000, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 30000, 'payment_method' => 'cash'])->assertCreated();

        $order->refresh();
        $this->assertSame('0.00', $order->balance_due);
        $this->assertSame('completed', $order->status);
        $this->assertSame('completed', $order->payment_status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'payment_method' => 'mtn_mobile_money', 'transaction_number' => 'MP1']);
        $this->assertCount(2, self::$pushes);

        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'Nothing is owed on this one.');
    }

    public function test_view_carries_lines_payments_and_the_customer(): void
    {
        $order = $this->order($this->customer(['name' => 'Jane']));
        Sanctum::actingAs($admin = $this->admin());
        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1000, 'payment_method' => 'cash']);

        $this->getJson("/api/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.created_by.name', 'Jane')
            ->assertJsonPath('data.payments.0.created_by.name', $admin->name)
            ->assertJsonMissingPath('data.created_by.device_token');
    }

    public function test_status_changes_are_validated_and_pushed_to_the_customer(): void
    {
        $order = $this->order($this->customer(['device_token' => 'phone-1']));
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/orders/{$order->id}", ['delivery_status' => 'flying'])->assertStatus(422);
        $this->postJson("/api/admin/orders/{$order->id}", ['delivery_status' => 'delivered'])->assertOk();

        $this->assertSame('delivered', $order->fresh()->delivery_status);
        $this->assertStringContainsString('delivered', self::$pushes[0][2]);
    }

    public function test_orders_with_payments_are_kept_on_bulk_delete(): void
    {
        $c = $this->customer();
        $paid = $this->order($c);
        $unpaid = $this->order($c);
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/orders/{$paid->id}/payments", ['amount' => 1000, 'payment_method' => 'cash']);

        $this->postJson('/api/admin/orders/bulk-delete', ['ids' => [$paid->id, $unpaid->id]])->assertStatus(422);
        $this->postJson('/api/admin/orders/bulk-delete', ['ids' => [$unpaid->id]])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $paid->id]);
        $this->assertDatabaseMissing('orders', ['id' => $unpaid->id]);
    }
}

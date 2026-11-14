<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use App\Support\SerialCounter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Numbers people read (docs/reference-numbers.md, Alpha's and NICE's pattern): PREFIX + Kampala month + counter, and a
 * number once handed out means one record forever, even after it is deleted.
 */
class SerialNumbersTest extends AdminTestCase
{
    private function bare(array $attributes = []): Order
    {
        // No order_number: the model numbers it (HasSerialNumber).
        return Order::forceCreate($attributes + [
            'status' => 'pending', 'amount' => 1000, 'charged_amount' => 1000, 'amount_paid' => 0, 'balance_due' => 1000,
            'payment_status' => 'pending', 'payment_mode' => 'cod', 'delivery_status' => 'pending', 'address' => 'Kampala',
            'created_by' => $this->customer()->id,
        ]);
    }

    public function test_orders_are_numbered_by_month_and_a_deleted_newest_number_never_comes_back(): void
    {
        $stem = 'ORD'.SerialCounter::monthStem();

        $first = $this->bare();
        $second = $this->bare();
        $second->delete();
        $third = $this->bare();

        $this->assertSame([$stem.'1', $stem.'2', $stem.'3'], [$first->order_number, $second->order_number, $third->order_number]);
    }

    public function test_an_order_entered_by_staff_and_a_package_get_their_numbers(): void
    {
        $gas = Product::forceCreate(['name' => 'Shell 12kg', 'price' => 90000, 'quantity' => 5, 'status' => 'active']);
        Sanctum::actingAs($this->admin());
        $month = SerialCounter::monthStem();

        $this->postJson('/api/admin/orders', [
            'customer_id' => $this->customer()->id, 'address' => 'Ntinda', 'payment_mode' => 'cod',
            'items' => [['product_id' => $gas->id, 'quantity' => 1, 'price' => 90000]],
        ])->assertCreated()->assertJsonPath('data.order_number', 'ORD'.$month.'1')->assertJsonPath('message', fn ($m) => str_contains($m, 'ORD'.$month.'1'));

        $this->postJson('/api/admin/packages', [
            'customer_id' => $this->customer()->id, 'name' => 'Documents', 'pickup' => 'Ntinda', 'destination' => 'Entebbe', 'charged_amount' => 5000,
        ])->assertCreated()->assertJsonPath('data.package_number', 'PKG'.$month.'1');
    }

    public function test_payments_get_a_receipt_number_of_their_own(): void
    {
        $order = $this->bare(['charged_amount' => 50000, 'balance_due' => 50000]);
        Sanctum::actingAs($this->admin());
        $stem = 'PAY'.SerialCounter::monthStem();

        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1000, 'payment_method' => 'cash', 'transaction_number' => 'MP123'])->assertCreated();
        $first = Payment::first();
        $this->assertSame($stem.'1', $first->receipt_number);
        $this->assertSame('MP123', $first->transaction_number, 'the customer\'s reference is kept beside ours');

        $this->postJson("/api/admin/payments/{$first->id}/delete")->assertOk();
        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1000, 'payment_method' => 'cash'])->assertCreated();
        $this->assertSame($stem.'2', Payment::first()->receipt_number, 'a deleted receipt number is never reissued');
    }

    // 22:00 UTC on 30 Nov is 01:00 on 1 Dec in Kampala: every report counts it in December, so its number says so.
    public function test_a_number_made_just_after_midnight_on_the_1st_takes_the_new_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 22:00:00', 'UTC'));

        $this->assertSame('ORD1220261', $this->bare()->order_number);
    }

    public function test_the_counter_starts_from_and_steps_past_numbers_already_in_the_table(): void
    {
        $stem = 'ORD'.SerialCounter::monthStem();
        $this->bare(['order_number' => $stem.'41']);
        $this->assertSame($stem.'42', $this->bare()->order_number);

        // One written by hand ahead of the counter is stepped over.
        $this->bare(['order_number' => $stem.'43']);
        $this->assertSame($stem.'44', $this->bare()->order_number);
        $this->assertEquals(44, DB::table('serial_counters')->where(['table_name' => 'orders', 'stem' => $stem])->value('last_value'));
    }

    public function test_old_random_numbers_are_left_alone_and_package_numbers_are_unique(): void
    {
        $old = $this->bare(['order_number' => 'GJO8CVEWV2']);
        $this->assertSame('GJO8CVEWV2', $old->fresh()->order_number);

        Package::forceCreate(['name' => 'A', 'package_number' => 'SAME123', 'created_by' => $this->customer()->id]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Package::forceCreate(['name' => 'B', 'package_number' => 'SAME123', 'created_by' => $this->customer()->id]);
    }
}

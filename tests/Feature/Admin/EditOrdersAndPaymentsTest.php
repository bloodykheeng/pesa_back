<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/** Edit is the whole order (what New sets, plus the charge); payments are instalments that can be corrected. */
class EditOrdersAndPaymentsTest extends AdminTestCase
{
    private function product(string $name, int $price, int $stock = 10): Product
    {
        return Product::forceCreate(['name' => $name, 'price' => $price, 'quantity' => $stock, 'status' => 'active']);
    }

    private function enter(array $items, array $extra = []): Order
    {
        $id = $this->postJson('/api/admin/orders', $extra + [
            'customer_id' => $this->customer()->id, 'address' => 'Ntinda', 'payment_mode' => 'cod', 'placed_at' => '2026-10-02T12:00:00.000Z', 'items' => $items,
        ])->assertCreated()->json('data.id');

        return Order::find($id);
    }

    public function test_editing_the_items_moves_stock_and_the_charge_follows(): void
    {
        $gas = $this->product('Shell 12kg', 90000);
        $water = $this->product('Elevate water', 10000);
        Sanctum::actingAs($this->admin());
        $order = $this->enter([['product_id' => $gas->id, 'quantity' => 2, 'price' => 90000]]);
        $this->assertSame(8, $gas->fresh()->quantity);

        $this->postJson("/api/admin/orders/{$order->id}", [
            'items' => [['product_id' => $gas->id, 'quantity' => 1, 'price' => 90000], ['product_id' => $water->id, 'quantity' => 3, 'price' => 10000]],
            'address' => 'Kira',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(9, $gas->fresh()->quantity, 'one gas back in stock');
        $this->assertSame(7, $water->fresh()->quantity);
        $this->assertSame('120000.00', $order->charged_amount);
        $this->assertSame('120000.00', $order->balance_due);
        $this->assertSame('Kira', $order->address);
        $this->assertSame(2, $order->orderProducts()->count());
        $this->assertDatabaseHas('activity_log', ['subject_type' => Order::class, 'subject_id' => $order->id, 'event' => 'updated']);
        $changes = json_decode(DB::table('activity_log')->where('subject_id', $order->id)->where('event', 'updated')->latest('id')->value('properties'), true)['changes'] ?? [];
        $this->assertContains('Items', array_column($changes, 'label'));
    }

    public function test_the_charge_can_differ_from_the_items_but_never_drops_below_what_is_paid(): void
    {
        $gas = $this->product('Shell 12kg', 90000);
        Sanctum::actingAs($this->admin());
        $order = $this->enter([['product_id' => $gas->id, 'quantity' => 1, 'price' => 90000]]);

        // A delivery fee on top.
        $this->postJson("/api/admin/orders/{$order->id}", ['charged_amount' => 95000])->assertOk();
        $this->assertSame('95000.00', $order->fresh()->balance_due);

        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 50000, 'payment_method' => 'cash', 'paid_at' => '2026-10-03T08:00:00.000Z'])->assertCreated();
        $this->postJson("/api/admin/orders/{$order->id}", ['charged_amount' => 40000])
            ->assertStatus(422)->assertJsonPath('errors.charged_amount.0', 'Less than what is already paid (50,000 UGX).');
        // Placed after its first payment: refused.
        $this->postJson("/api/admin/orders/{$order->id}", ['placed_at' => '2026-10-04T08:00:00.000Z'])->assertStatus(422)->assertJsonValidationErrors('placed_at');

        // Lowering it to what is paid completes the order.
        $this->postJson("/api/admin/orders/{$order->id}", ['charged_amount' => 50000])->assertOk();
        $this->assertSame('completed', $order->fresh()->payment_status);
    }

    public function test_instalments_add_up_and_a_corrected_or_deleted_payment_reopens_the_balance(): void
    {
        $gas = $this->product('Shell 12kg', 90000);
        Sanctum::actingAs($this->admin());
        $order = $this->enter([['product_id' => $gas->id, 'quantity' => 1, 'price' => 90000]]);

        foreach ([30000, 30000, 30000] as $i => $amount) {
            $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => $amount, 'payment_method' => 'mtn_mobile_money', 'paid_at' => '2026-10-0'.($i + 3).'T08:00:00.000Z'])->assertCreated();
        }
        $order->refresh();
        $this->assertSame('0.00', $order->balance_due);
        $this->assertSame('completed', $order->payment_status);
        $this->assertSame('completed', $order->status);

        $last = Payment::where('order_id', $order->id)->latest('id')->first();
        $this->postJson("/api/admin/payments/{$last->id}", ['amount' => 20000, 'payment_method' => 'cash'])->assertOk();
        $order->refresh();
        $this->assertSame('10000.00', $order->balance_due);
        $this->assertSame('pending', $order->payment_status, 'owing again');
        $this->assertSame('pending', $order->status);
        $this->postJson("/api/admin/payments/{$last->id}", ['amount' => 40000, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'More than is owed with this payment (30,000 UGX).');

        $this->postJson("/api/admin/payments/{$last->id}/delete")->assertOk();
        $this->assertSame('30000.00', $order->fresh()->balance_due);
        $this->assertSame('60000.00', $order->fresh()->amount_paid);
    }

    public function test_editors_correct_payments_but_only_admins_delete_them(): void
    {
        $order = $this->order($this->customer());
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1000, 'payment_method' => 'cash'])->assertCreated();
        $payment = Payment::first();

        Sanctum::actingAs($this->editor());
        $this->postJson("/api/admin/payments/{$payment->id}", ['amount' => 2000, 'payment_method' => 'cash'])->assertOk();
        $this->postJson("/api/admin/payments/{$payment->id}/delete")->assertForbidden();
    }

    public function test_a_legacy_paid_amount_with_no_payment_rows_survives_a_new_payment(): void
    {
        // The old admin let staff type "Amount paid" on a package directly.
        $package = Package::forceCreate([
            'name' => 'Book', 'pickup' => 'Bukoto', 'destination' => 'Kasubi', 'charged_amount' => 10000, 'amount_paid' => 5000, 'balance_due' => 5000,
            'payment_status' => 'pending', 'delivery_status' => 'pending', 'status' => 'pending', 'created_by' => $this->customer()->id,
        ]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/packages/{$package->id}/payments", ['amount' => 5000, 'payment_method' => 'cash'])->assertCreated();
        $package->refresh();
        $this->assertSame('10000.00', $package->amount_paid);
        $this->assertSame('0.00', $package->balance_due);
        $this->assertSame('Paid', $package->payment_status);
    }

    public function test_an_entered_order_rings_other_staff_not_the_one_who_typed_it(): void
    {
        $gas = $this->product('Shell 12kg', 90000);
        $other = $this->admin(['name' => 'Other Admin']);
        Sanctum::actingAs($me = $this->admin(['name' => 'Typist']));
        $this->enter([['product_id' => $gas->id, 'quantity' => 1, 'price' => 90000]]);

        $orderAlerts = fn ($user) => $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? '') === 'order');
        $this->assertCount(0, $orderAlerts($me));
        $alert = $orderAlerts($other)->first()->data;
        $this->assertStringContainsString('entered', $alert['title']);
        $this->assertStringContainsString('entered by Typist', $alert['body']);
    }
}

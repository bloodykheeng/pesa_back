<?php

namespace Tests\Feature\Admin;

use App\Events\StaffSignal;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\StaffAlert;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/** Live signals through Reverb instead of polling (docs/realtime.md). */
class RealtimeTest extends AdminTestCase
{
    /** A Reverb connection that nothing listens on: auth works (it only signs), sending fails. */
    private function reverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => '1',
                'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
                'client_options' => ['timeout' => 1, 'connect_timeout' => 1],
            ],
        ]);
        // The channels were registered on the test's "null" broadcaster at boot: register them on Reverb's.
        app(\Illuminate\Broadcasting\BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
    }

    public function test_staff_join_the_staff_channel_and_customers_and_strangers_are_refused(): void
    {
        $this->reverb();
        $admin = $this->admin();
        $auth = fn (string $channel) => $this->postJson('/api/admin/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);

        Sanctum::actingAs($admin);
        $auth('private-staff')->assertOk()->assertJsonStructure(['auth']);
        $auth('private-App.Models.User.'.$admin->id)->assertOk();
        $auth('private-App.Models.User.'.($admin->id + 999))->assertForbidden();

        Sanctum::actingAs($this->editor());
        $auth('private-staff')->assertOk();

        Sanctum::actingAs($this->customer());
        $auth('private-staff')->assertForbidden();
    }

    public function test_the_right_signals_fire(): void
    {
        Event::fake([StaffSignal::class]);
        $staff = $this->admin();
        $gas = Product::forceCreate(['name' => 'Shell 12kg', 'price' => 90000, 'quantity' => 5, 'status' => 'active']);
        Sanctum::actingAs($this->editor());

        $this->postJson('/api/admin/orders', [
            'customer_id' => $this->customer()->id, 'address' => 'Ntinda', 'payment_mode' => 'cod',
            'items' => [['product_id' => $gas->id, 'quantity' => 1, 'price' => 90000]],
        ])->assertCreated();

        Event::assertDispatched(StaffSignal::class, fn (StaffSignal $s) => $s->topic === 'records' && $s->data['kind'] === 'order');
        // The bell alert stored for another staff member refreshes their bell, on their own channel.
        Event::assertDispatched(StaffSignal::class, fn (StaffSignal $s) => $s->topic === 'inbox' && $s->userId === $staff->id);

        $staff->notify(new StaffAlert('send', 'Sent', 'Done.'));
        Event::assertDispatched(StaffSignal::class, fn (StaffSignal $s) => $s->topic === 'inbox' && $s->userId === $staff->id);
        $this->assertSame(['private-App.Models.User.'.$staff->id], array_map(fn ($c) => $c->name, (new StaffSignal('inbox', [], $staff->id))->broadcastOn()));
        $this->assertSame(['private-staff'], array_map(fn ($c) => $c->name, (new StaffSignal('queues'))->broadcastOn()));
    }

    public function test_reverb_being_down_never_breaks_an_order(): void
    {
        $this->reverb();
        $gas = Product::forceCreate(['name' => 'Shell 12kg', 'price' => 90000, 'quantity' => 5, 'status' => 'active']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/orders', [
            'customer_id' => $this->customer()->id, 'address' => 'Ntinda', 'payment_mode' => 'cod',
            'items' => [['product_id' => $gas->id, 'quantity' => 1, 'price' => 90000]],
        ])->assertCreated();
        $this->assertSame(1, Order::count());
    }
}

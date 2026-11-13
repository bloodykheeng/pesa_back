<?php

namespace Tests\Feature\Admin;

use App\Models\AdminNotification;
use App\Models\AdminNotificationRecipient;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

class AdminUsersAndMessagesTest extends AdminTestCase
{
    public function test_admins_add_edit_and_delete_accounts_with_guards(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/admin/users', [
            'name' => 'New Editor', 'email' => 'ed@example.com', 'phone' => '256711111111', 'status' => 'active',
            'role' => 'Editor', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated()->assertJsonPath('data.role', 'Editor')->json('data.id');

        $this->postJson('/api/admin/users', ['name' => 'Dup', 'email' => 'ed@example.com', 'status' => 'active', 'role' => 'Customer', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson("/api/admin/users/{$id}", ['name' => 'Renamed', 'email' => 'ed@example.com', 'status' => 'inactive', 'role' => 'Customer'])
            ->assertOk()->assertJsonPath('data.role', 'Customer');

        $this->postJson("/api/admin/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'status' => 'active', 'role' => 'Customer'])
            ->assertStatus(422);
        $this->postJson('/api/admin/users/bulk-delete', ['ids' => [$admin->id]])->assertStatus(422);

        $buyer = $this->customer();
        $this->order($buyer);
        $this->postJson('/api/admin/users/bulk-delete', ['ids' => [$buyer->id]])->assertStatus(422);
        $this->postJson('/api/admin/users/bulk-delete', ['ids' => [$id]])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $id]);
    }

    public function test_the_users_list_filters_by_role_and_shows_totals_on_view(): void
    {
        $buyer = $this->customer(['name' => 'Jane']);
        $this->order($buyer, ['charged_amount' => 30000, 'amount_paid' => 10000, 'balance_due' => 20000]);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/users?filter[role]=Customer')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.orders_count', 1);
        $this->getJson("/api/admin/users/{$buyer->id}")
            ->assertOk()->assertJsonPath('data.totals.balance_due', 20000)->assertJsonPath('data.role', 'Customer');
    }

    public function test_a_message_to_picked_customers_goes_by_push_and_email_with_results_per_person(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $withPhone = $this->customer(['device_token' => 'phone-1']);
        $broken = $this->customer(['device_token' => 'broken-token']);
        $noPhone = $this->customer(['device_token' => null]);
        $this->customer(['device_token' => 'phone-x']); // not picked
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/notifications/preview', ['audience' => 'selected', 'user_ids' => [$withPhone->id, $broken->id, $noPhone->id]])
            ->assertOk()->assertJson(['recipients' => 3, 'with_device' => 2, 'with_email' => 3]);

        $this->postJson('/api/admin/notifications', [
            'title' => 'Gas promo', 'body' => '10% off this week', 'send_push' => true, 'send_email' => true,
            'audience' => 'selected', 'user_ids' => [$withPhone->id, $broken->id, $noPhone->id],
        ])->assertStatus(202);

        $n = AdminNotification::firstOrFail();
        $this->assertSame('sent', $n->status);
        $this->assertSame([1, 1, 3], [$n->push_sent, $n->push_failed, $n->email_sent]);
        $this->assertSame([['phone-1', 'Gas promo', '10% off this week']], self::$pushes);
        $this->assertSame('skipped', AdminNotificationRecipient::where('user_id', $noPhone->id)->value('push_status'));
        $this->assertStringContainsString('not found', AdminNotificationRecipient::where('user_id', $broken->id)->value('error'));

        // The sender's bell says it is done, and the history shows it.
        $kinds = collect($this->getJson('/api/admin/inbox?per_page=50')->json('data'))->pluck('kind');
        $this->assertSame(1, $kinds->filter(fn ($k) => $k === 'send')->count());
        $this->getJson('/api/admin/notifications')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/admin/notifications/{$n->id}/recipients?filter[push_status]=failed")->assertJsonPath('meta.total', 1);
    }

    public function test_a_message_to_all_customers_skips_staff_and_needs_a_channel(): void
    {
        $admin = $this->admin(['device_token' => 'staff-phone']);
        $this->customer(['device_token' => 'c1']);
        $this->customer(['device_token' => 'c2']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/notifications', ['title' => 'Hi', 'body' => 'x', 'send_push' => false, 'send_email' => false, 'audience' => 'all_customers'])
            ->assertStatus(422);
        $this->postJson('/api/admin/notifications', ['title' => 'Hi', 'body' => 'x', 'send_push' => true, 'send_email' => false, 'audience' => 'all_customers'])
            ->assertStatus(202);

        $this->assertCount(2, self::$pushes);
        $this->assertNotContains('staff-phone', array_column(self::$pushes, 0));
    }

    public function test_an_email_send_over_the_daily_limit_is_refused(): void
    {
        config(['app.mail_daily_limit' => 2]);
        Mail::fake();
        foreach (range(1, 3) as $i) {
            $this->customer();
        }
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/notifications', ['title' => 'Hi', 'body' => 'x', 'send_push' => false, 'send_email' => true, 'audience' => 'all_customers'])
            ->assertStatus(422)->assertJsonValidationErrors('send_email');
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_new_orders_and_sign_ups_ring_every_staff_bell(): void
    {
        $admin = $this->admin();
        $editor = $this->editor();
        $customer = $this->customer();
        $this->order($customer, ['order_number' => 'ABC123']);

        Sanctum::actingAs($editor);
        $titles = collect($this->getJson('/api/admin/inbox?unread=1&per_page=50')->json('data'))->pluck('title');
        $this->assertContains('New order #ABC123', $titles);
        $this->assertContains('New customer', $titles);

        Sanctum::actingAs($admin);
        $id = $this->getJson('/api/admin/inbox')->json('data.0.id');
        $count = $this->getJson('/api/admin/inbox')->json('unread_count');
        $this->postJson("/api/admin/inbox/{$id}/read")->assertJsonPath('unread_count', $count - 1);
        $this->postJson('/api/admin/inbox/read-all')->assertJsonPath('unread_count', 0);
    }

    public function test_the_dashboard_counts_match_the_lists(): void
    {
        $c = $this->customer();
        $this->order($c, ['charged_amount' => 10000, 'balance_due' => 10000]);
        $this->order($c, ['charged_amount' => 5000, 'balance_due' => 0, 'amount_paid' => 5000]);
        $this->order($c, ['status' => 'cancelled', 'charged_amount' => 99999, 'balance_due' => 99999]);
        Sanctum::actingAs($this->admin());

        $d = $this->getJson('/api/admin/dashboard')->assertOk();
        $d->assertJsonPath('orders.count', 3)->assertJsonPath('orders.cancelled', 1)
            ->assertJsonPath('orders.value', 15000)->assertJsonPath('orders.balance_due', 10000)
            ->assertJsonPath('orders.with_balance', 1)->assertJsonPath('customers.total', 1);

        $this->getJson('/api/admin/orders?filter[has_balance]=1')->assertJsonPath('meta.total', $d->json('orders.with_balance'));
        $this->getJson('/api/admin/dashboard?from=2000-01-01&to=2000-01-02')->assertJsonPath('orders.count', 0);
    }
}

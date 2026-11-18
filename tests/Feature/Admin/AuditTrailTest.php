<?php

namespace Tests\Feature\Admin;

use App\Contracts\HasSnapshotSchema;
use App\Models\ElectronicBrand;
use App\Models\ElectronicCategory;
use App\Models\Product;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Audit\Snapshot;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

class AuditTrailTest extends AdminTestCase
{
    /**
     * Write what the arranging wrote (as a request would have), so the action under test starts
     * its own entries, like a real request.
     */
    private function settle(): void
    {
        \App\Support\Audit\Audit::flush();
    }

    /** The entries written for a subject, oldest first. */
    private function entriesFor(object $subject)
    {
        return Activity::where('subject_type', $subject::class)->where('subject_id', $subject->id)->orderBy('id')->get();
    }

    private function field(array $entries, string $field): ?array
    {
        return collect($entries)->firstWhere('field', $field);
    }

    public function test_an_order_placed_in_the_app_is_one_entry_with_its_items_and_the_customer(): void
    {
        $customer = $this->customer(['name' => 'Jane Shopper']);
        $product = Product::forceCreate(['name' => 'Shell gas 12kg', 'price' => 110000, 'quantity' => 10, 'status' => 'active']);
        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', [
            'amount' => 110000, 'payment_mode' => 'cod', 'address' => 'Bukoto',
            'products' => [['name' => 'Shell gas 12kg', 'price' => 110000, 'product_id' => $product->id, 'quantity' => 1]],
        ])->assertSuccessful();

        $order = \App\Models\Order::latest('id')->first();
        $entries = $this->entriesFor($order);
        $this->assertCount(1, $entries, 'the order and its lines are one create entry');

        $entry = $entries->first();
        $this->assertSame('created', $entry->event);
        $this->assertSame('Orders', $entry->log_name);
        $this->assertSame($customer->id, $entry->causer_id);
        $this->assertSame('app', $entry->properties['source']);
        $this->assertSame('Jane Shopper', $entry->properties['causer']['name']);
        $this->assertSame('Created Order #'.$order->order_number, $entry->description);

        $snapshot = $entry->properties['snapshot'];
        $this->assertSame([['name' => 'Shell gas 12kg', 'quantity' => 1, 'price' => 110000]], $this->field($snapshot, 'items')['value']);
        $this->assertSame(['value' => 'cod', 'label' => 'Cash on delivery'], $this->field($snapshot, 'payment_mode')['value']);
        $this->assertSame('Jane Shopper', $this->field($snapshot, 'created_by')['value']['label']);
    }

    public function test_an_edit_records_before_and_after_with_names_not_ids(): void
    {
        $phones = ElectronicCategory::forceCreate(['name' => 'Mobile Phones', 'status' => 'active']);
        $tvs = ElectronicCategory::forceCreate(['name' => 'TVs', 'status' => 'active']);
        $brand = ElectronicBrand::forceCreate(['name' => 'Itel', 'status' => 'active', 'electronic_categories_id' => $phones->id]);
        Sanctum::actingAs($this->admin(['name' => 'Boss']));

        $this->settle();
        $this->post("/api/admin/electronic-brands/{$brand->id}", ['name' => 'Itel', 'status' => 'inactive', 'electronic_categories_id' => $tvs->id], ['Accept' => 'application/json'])->assertOk();

        $entry = $this->entriesFor($brand)->last();
        $this->assertSame('updated', $entry->event);
        $this->assertSame('Fuel brands', $entry->log_name);
        $this->assertSame('admin', $entry->properties['source']);
        $changes = $entry->properties['changes'];
        $this->assertSame(['Category', 'Status'], array_column($changes, 'label'), 'only what changed, in the form\'s order');
        $this->assertSame('Mobile Phones', $changes[0]['old']['label']);
        $this->assertSame('TVs', $changes[0]['new']['label']);

        // Renaming the category later never rewrites what the entry says.
        $tvs->update(['name' => 'Televisions']);
        $this->assertSame('TVs', $entry->fresh()->properties['changes'][0]['new']['label']);
    }

    public function test_a_deleted_order_keeps_its_snapshot_with_items(): void
    {
        $order = $this->order($this->customer());
        $order->orderProducts()->create(['name' => 'Water 20L', 'price' => 10000, 'quantity' => 2]);
        Sanctum::actingAs($this->admin());
        $this->settle();

        $this->postJson('/api/admin/orders/bulk-delete', ['ids' => [$order->id]])->assertOk();

        $entry = Activity::where('event', 'deleted')->where('subject_id', $order->id)->sole();
        $this->assertSame('Deleted Order #'.$order->order_number, $entry->description);
        $this->assertSame('Water 20L', $this->field($entry->properties['snapshot'], 'items')['value'][0]['name']);
    }

    public function test_passwords_are_never_written_and_role_changes_are(): void
    {
        $user = $this->customer(['name' => 'Sam']);
        Sanctum::actingAs($this->admin());
        $this->settle();

        $this->postJson("/api/admin/users/{$user->id}", [
            'name' => 'Sam', 'email' => $user->email, 'role' => 'Editor', 'status' => 'active',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ])->assertOk();

        $entry = $this->entriesFor($user)->last();
        $this->assertStringNotContainsString('secret-pass-1', json_encode($entry->properties));
        $this->assertStringNotContainsString($user->fresh()->password, json_encode($entry->properties));
        $password = $this->field($entry->properties['changes'], 'password');
        $this->assertSame(['old' => null, 'new' => null], ['old' => $password['old'], 'new' => $password['new']]);
        $role = $this->field($entry->properties['changes'], 'role');
        $this->assertSame(['Customer', 'Editor'], [$role['old'], $role['new']]);
    }

    public function test_sign_ins_and_failed_attempts_are_recorded(): void
    {
        $this->admin(['email' => 'boss@example.com']);
        $this->settle();

        $this->postJson('/api/admin/auth/login', ['email' => 'boss@example.com', 'password' => 'nope'])->assertStatus(422);
        $token = $this->postJson('/api/admin/auth/login', ['email' => 'boss@example.com', 'password' => 'password'])->json('token');
        $this->withToken($token)->postJson('/api/admin/auth/logout')->assertOk();

        $this->assertSame(['sign_in_failed', 'signed_in', 'signed_out'], Activity::where('log_name', 'Sign-ins')->orderBy('id')->pluck('event')->all());
        $this->assertSame('boss@example.com', Activity::where('event', 'sign_in_failed')->sole()->properties['extra']['login']);
        $this->assertSame('Signed in to the admin dashboard', Activity::where('event', 'signed_in')->sole()->description);
    }

    public function test_reset_requests_and_resets_are_recorded_under_sign_ins(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->customer(['email' => 'shopper@example.com']);
        $this->customer(['email' => 'gone@example.com', 'status' => 'deactive']);
        $this->settle();

        foreach (['shopper@example.com', 'nobody@example.com', 'gone@example.com'] as $email) {
            $this->postJson('/api/admin/auth/forgot-password', ['email' => $email])->assertOk();
        }
        $token = \App\Models\PasswordReset::where('email', 'shopper@example.com')->value('token');
        $this->postJson('/api/reset-password', ['token' => $token, 'password' => 'newpass1', 'password_confirmation' => 'newpass1'])->assertOk();

        $asked = Activity::where('event', 'password_reset_requested')->orderBy('id')->get();
        $this->assertSame(['emailed', 'no_account', 'inactive'], $asked->map(fn ($a) => $a->properties['extra']['outcome'])->all());
        $this->assertSame(['shopper@example.com', 'nobody@example.com', 'gone@example.com'], $asked->map(fn ($a) => $a->properties['extra']['login'])->all());
        $this->assertSame('Password reset link emailed', $asked[0]->description);

        $done = Activity::where('event', 'password_reset')->sole();
        $this->assertSame('Password changed with a reset link', $done->description);
        $this->assertSame('shopper@example.com', $done->properties['causer']['email']);
        // The password edit itself names the account holder, not "someone signing up".
        $edit = Activity::where('event', 'updated')->where('log_name', 'Accounts')->latest('id')->first();
        $this->assertSame('shopper@example.com', $edit->properties['causer']['email']);

        Sanctum::actingAs($this->admin());
        $rows = collect($this->getJson('/api/admin/audit-trail?filter[kind]=sign_ins&per_page=50')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['password_reset_requested', 'password_reset'], $rows->pluck('event')->unique()->values()->all());
        $this->assertContains('nobody@example.com', $rows->pluck('login')->all());
    }

    public function test_only_admins_read_the_trail_and_a_record_history_filters_to_it(): void
    {
        $product = Product::forceCreate(['name' => 'Tecno Pop 8', 'price' => 300000, 'quantity' => 10, 'status' => 'active']);
        Sanctum::actingAs($this->admin());
        $this->settle();
        $this->post("/api/admin/products/{$product->id}", ['name' => 'Tecno Pop 8', 'price' => 280000, 'status' => 'active', 'inventory_types_id' => null], ['Accept' => 'application/json']);
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->editor());
        $this->getJson('/api/admin/audit-trail')->assertForbidden();

        Sanctum::actingAs($this->admin());
        $list = $this->getJson('/api/admin/audit-trail?filter[subject_type]=Product&filter[subject_id]='.$product->id)->assertOk();
        $this->assertGreaterThanOrEqual(1, $list->json('meta.total'));
        $this->assertSame('Product', $list->json('data.0.subject_type'));

        $id = $list->json('data.0.id');
        $this->getJson("/api/admin/audit-trail/{$id}")->assertOk()->assertJsonStructure(['data' => ['request' => ['id', 'method', 'path', 'ip']]]);
    }

    public function test_every_audited_model_declares_a_schema_with_known_types(): void
    {
        foreach (AppServiceProvider::AUDITED as $class) {
            $this->assertTrue(is_subclass_of($class, HasSnapshotSchema::class), "{$class} implements HasSnapshotSchema");
            foreach ($class::snapshotSchema() as $field => $def) {
                if (isset($def['type'])) {
                    $this->assertContains($def['type'], Snapshot::TYPES, "{$class}::{$field}");
                }
                if (($def['type'] ?? null) === 'relation') {
                    $this->assertIsCallable($def['resolve'] ?? null, "{$class}::{$field} needs a resolver");
                }
            }
        }
        $this->assertNotEmpty(User::snapshotSchema());
    }
}

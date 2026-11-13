<?php

namespace Tests\Feature\Admin;

use App\Mail\AccessChanged;
use App\Mail\AccountCreated;
use App\Mail\PasswordChanged;
use App\Models\PasswordReset;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/** The emails an account gets about itself (App\Services\AccountEmails). */
class AccountEmailsTest extends AdminTestCase
{
    public function test_an_account_an_admin_makes_gets_a_welcome_with_a_7_day_link_and_no_password(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin(['name' => 'Boss']));

        $this->postJson('/api/admin/users', [
            'name' => 'New Editor', 'email' => 'ed@example.com', 'status' => 'active', 'role' => 'Editor',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        Mail::assertSent(AccountCreated::class, function (AccountCreated $mail) {
            $html = $mail->render();
            return $mail->hasTo('ed@example.com')
                && $mail->staff
                && str_contains($mail->link, config('app.admin_url') . '/reset-password?token=')
                && str_contains($html, 'Boss made an account for you')
                && ! str_contains($html, 'secret123');
        });
        $this->assertTrue(PasswordReset::where('email', 'ed@example.com')->value('expires_at') > now()->addDays(6));
        Mail::assertNotSent(AccountCreated::class, fn ($m) => $m->hasTo('boss@example.com'));
    }

    public function test_the_welcome_link_works_for_days_not_just_an_hour(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/admin/users', ['name' => 'Ed', 'email' => 'ed@example.com', 'status' => 'active', 'role' => 'Editor', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
        $token = PasswordReset::where('email', 'ed@example.com')->value('token');

        $this->travel(3)->days();
        $this->get("/api/reset-password?token={$token}")->assertOk();

        $this->travel(5)->days();
        $this->get("/api/reset-password?token={$token}")->assertStatus(410);
    }

    public function test_a_customer_who_signs_up_in_the_app_is_welcomed_with_the_api_reset_page(): void
    {
        Mail::fake();
        $customer = $this->customer(['email' => 'shopper@example.com']);

        Mail::assertSent(AccountCreated::class, fn (AccountCreated $m) => $m->hasTo('shopper@example.com') && str_contains($m->link, '/api/reset-password?token='));
        $this->assertNotNull($customer);
    }

    public function test_password_changes_send_a_notice_however_they_happen(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $editor = $this->editor();

        Sanctum::actingAs($editor);
        $this->postJson('/api/admin/auth/password', ['current_password' => 'password', 'password' => 'brandnew1', 'password_confirmation' => 'brandnew1'])->assertOk();
        Mail::assertSent(PasswordChanged::class, fn (PasswordChanged $m) => $m->hasTo($editor->email) && $m->how === 'from your profile');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$editor->id}", ['name' => $editor->name, 'email' => $editor->email, 'status' => 'active', 'role' => 'Editor', 'password' => 'another12', 'password_confirmation' => 'another12'])->assertOk();
        Mail::assertSent(PasswordChanged::class, fn (PasswordChanged $m) => $m->how === 'by an administrator' && $m->byName === $admin->name);
    }

    public function test_role_and_status_changes_are_told_and_inactive_accounts_are_signed_out(): void
    {
        Mail::fake();
        $editor = $this->editor();
        $editor->createToken('phone');
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/users/{$editor->id}", ['name' => $editor->name, 'email' => $editor->email, 'status' => 'inactive', 'role' => 'Admin'])->assertOk();

        Mail::assertSent(AccessChanged::class, fn (AccessChanged $m) => $m->changes === ['role' => ['Editor', 'Admin'], 'status' => ['active', 'inactive']]);
        $this->assertSame(0, $editor->tokens()->count());

        Mail::fake();
        $this->postJson("/api/admin/users/{$editor->id}", ['name' => 'Renamed', 'email' => $editor->email, 'status' => 'inactive', 'role' => 'Admin'])->assertOk();
        Mail::assertNotSent(AccessChanged::class);
    }

    public function test_a_mail_failure_never_breaks_the_save(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/users', ['name' => 'Ed', 'email' => 'ed@example.com', 'status' => 'active', 'role' => 'Editor', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertCreated();
    }
}

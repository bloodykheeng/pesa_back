<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

class AdminAuthTest extends AdminTestCase
{
    public function test_staff_sign_in_and_get_a_token_with_their_profile(): void
    {
        $admin = $this->admin(['email' => 'boss@example.com']);

        $response = $this->postJson('/api/admin/auth/login', ['email' => 'boss@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.email', 'boss@example.com')
            ->assertJsonPath('user.role', 'Admin')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.device_token');

        $this->assertNotEmpty($response->json('token'));
        $this->assertNotNull($admin->fresh()->lastlogin);
    }

    public function test_customers_and_wrong_passwords_get_the_same_refusal(): void
    {
        $this->customer(['email' => 'shopper@example.com']);
        $this->admin(['email' => 'boss@example.com']);

        $this->postJson('/api/admin/auth/login', ['email' => 'shopper@example.com', 'password' => 'password'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Wrong email or password.');
        $this->postJson('/api/admin/auth/login', ['email' => 'boss@example.com', 'password' => 'nope'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Wrong email or password.');
    }

    public function test_an_inactive_staff_account_cannot_sign_in(): void
    {
        $this->admin(['email' => 'gone@example.com', 'status' => 'inactive']);

        $this->postJson('/api/admin/auth/login', ['email' => 'gone@example.com', 'password' => 'password'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'This account is not active. Ask an administrator.');
    }

    public function test_sign_out_ends_the_token(): void
    {
        $this->admin(['email' => 'boss@example.com']);
        $token = $this->postJson('/api/admin/auth/login', ['email' => 'boss@example.com', 'password' => 'password'])->json('token');

        $this->withToken($token)->getJson('/api/admin/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/admin/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_forgot_password_mails_staff_and_customers_and_answers_alike_naming_the_address(): void
    {
        Mail::fake();
        $this->admin(['email' => 'boss@example.com']);
        $this->customer(['email' => 'shopper@example.com']);
        $this->customer(['email' => 'gone@example.com', 'status' => 'deactive']);
        $welcomeToken = \App\Models\PasswordReset::where('email', 'shopper@example.com')->value('token');

        $answer = fn (string $email) => $this->postJson('/api/admin/auth/forgot-password', ['email' => $email])->assertOk()->json('message');
        $template = fn (string $email) => str_replace($email, '<email>', $answer($email));

        $this->assertSame(
            'If boss@example.com has an Elevate Pesa account, a reset link is on its way. It works for 60 minutes.',
            $answer('boss@example.com'),
        );
        // Unknown and inactive addresses read exactly like real ones.
        $this->assertSame($template('shopper@example.com'), $template('nobody@example.com'));
        $this->assertSame($template('shopper@example.com'), $template('gone@example.com'));

        // Emails to the staff member and the customer only (queued; the customer asked twice above).
        Mail::assertQueued(\App\Mail\ViewMail::class, fn ($m) => $m->hasTo('boss@example.com'));
        Mail::assertQueued(\App\Mail\ViewMail::class, fn ($m) => $m->hasTo('shopper@example.com'));
        Mail::assertNotQueued(\App\Mail\ViewMail::class, fn ($m) => $m->hasTo('gone@example.com') || $m->hasTo('nobody@example.com'));

        // The customer's new link replaces the welcome link, lasts 60 minutes and saves through the public endpoint.
        $reset = \App\Models\PasswordReset::where('email', 'shopper@example.com')->first();
        $this->assertNotSame($welcomeToken, $reset->token);
        $this->assertNull($reset->expires_at);
        $this->postJson('/api/reset-password', ['token' => $reset->token, 'password' => 'newpass1', 'password_confirmation' => 'newpass1'])->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpass1', \App\Models\User::where('email', 'shopper@example.com')->value('password')));
    }

    public function test_customers_cannot_use_the_admin_api_and_editors_cannot_manage_accounts(): void
    {
        Sanctum::actingAs($this->customer());
        $this->getJson('/api/admin/orders')->assertForbidden();
        $this->getJson('/api/admin/dashboard')->assertForbidden();

        Sanctum::actingAs($this->editor());
        $this->getJson('/api/admin/orders')->assertOk();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/admin/notifications', [])->assertForbidden();
    }

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/admin/orders')->assertUnauthorized();
    }

    public function test_staff_change_their_own_profile_and_password(): void
    {
        $editor = $this->editor();
        $other = $editor->createToken('other-device');
        Sanctum::actingAs($editor);

        $this->postJson('/api/admin/auth/profile', ['name' => 'New Name', 'phone' => '256700000009'])->assertOk()->assertJsonPath('user.name', 'New Name');

        $this->postJson('/api/admin/auth/password', ['current_password' => 'wrong', 'password' => 'brandnew1', 'password_confirmation' => 'brandnew1'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/api/admin/auth/password', ['current_password' => 'password', 'password' => 'brandnew1', 'password_confirmation' => 'brandnew1'])->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('brandnew1', $editor->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
    }
}

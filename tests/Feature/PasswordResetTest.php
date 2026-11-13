<?php

namespace Tests\Feature;

use App\Models\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Forgot / reset password (PasswordResetController). The emailed link opens
 * GET /api/reset-password?token=..., the form posts back to the same address.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function resetFor(string $email, string $token = 'valid-token-123', $createdAt = null): void
    {
        // Replaces the welcome email's link, which a new account already has.
        PasswordReset::updateOrCreate(['email' => $email], ['token' => $token, 'created_at' => $createdAt ?? now(), 'expires_at' => null]);
    }

    public function test_a_posted_user_id_without_a_token_cannot_change_a_password(): void
    {
        $admin = $this->admin();

        $this->post('/api/reset-password', ['id' => $admin->id, 'password' => 'hacked1', 'password_confirmation' => 'hacked1'])
            ->assertStatus(410);

        $this->assertFalse(Hash::check('hacked1', $admin->fresh()->password));
    }

    public function test_a_token_resets_only_its_own_user_whatever_id_is_posted(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $this->resetFor($customer->email);

        $this->post('/api/reset-password', [
            'token' => 'valid-token-123', 'id' => $admin->id,
            'password' => 'newpass1', 'password_confirmation' => 'newpass1',
        ])->assertOk()->assertSee('Password changed');

        $this->assertTrue(Hash::check('newpass1', $customer->fresh()->password));
        $this->assertFalse(Hash::check('newpass1', $admin->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $customer->email]);
    }

    public function test_the_link_page_opens_for_a_valid_token_and_refuses_bad_or_old_ones(): void
    {
        $customer = $this->customer();
        $this->resetFor($customer->email);

        $this->get('/api/reset-password?token=valid-token-123')->assertOk()->assertSee('Choose a new password');
        $this->get('/api/reset-password?token=wrong')->assertStatus(410)->assertSee('expired');
        $this->get('/api/reset-password')->assertStatus(410);

        $this->resetFor($this->customer()->email, 'old-token', now()->subMinutes(61));
        $this->get('/api/reset-password?token=old-token')->assertStatus(410);
    }

    public function test_json_callers_get_json_and_validation_messages(): void
    {
        $customer = $this->customer();
        $this->resetFor($customer->email);

        $this->postJson('/api/reset-password', ['token' => 'valid-token-123', 'password' => 'abc', 'password_confirmation' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/reset-password', ['token' => 'valid-token-123', 'password' => 'goodpass', 'password_confirmation' => 'goodpass'])
            ->assertOk()->assertJsonPath('message', 'Your password has been changed. You can sign in now.');
    }

    public function test_changing_the_password_signs_out_every_device(): void
    {
        $customer = $this->customer();
        $customer->createToken('phone');
        $this->resetFor($customer->email);

        $this->postJson('/api/reset-password', ['token' => 'valid-token-123', 'password' => 'goodpass', 'password_confirmation' => 'goodpass'])->assertOk();

        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_forgot_password_emails_a_link_and_keeps_its_response(): void
    {
        Mail::fake();
        $customer = $this->customer();

        $this->postJson('/api/forgot-password', ['email' => $customer->email])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $customer->email]);
    }
}

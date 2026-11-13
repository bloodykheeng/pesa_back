<?php

namespace App\Services;

use App\Mail\AccessChanged;
use App\Mail\AccountCreated;
use App\Mail\PasswordChanged;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The emails an account gets about itself: welcome (with a link to choose a password), password
 * changed, access changed. A failed send is logged and never breaks the request that caused it.
 * Passwords are never put in an email.
 */
class AccountEmails
{
    /** How long a welcome email's "set your password" link works. */
    public const WELCOME_LINK_DAYS = 7;

    /** A new account: made by an admin (`$by`) or signed up in the app (`$by` null). */
    public function created(User $user, ?User $by = null): void
    {
        $this->send($user, fn () => new AccountCreated($user, $this->isStaff($user), $by?->name, $this->setPasswordLink($user)));
    }

    /** "Your password was changed", saying how, so someone who did not change it can act. */
    public function passwordChanged(User $user, string $how, ?User $by = null): void
    {
        $this->send($user, fn () => new PasswordChanged($user, $how, $by?->name, $this->isStaff($user)));
    }

    /** A role or status change, made by an admin. */
    public function accessChanged(User $user, array $changes, ?User $by = null): void
    {
        if (empty($changes)) {
            return;
        }
        $this->send($user, fn () => new AccessChanged($user, $changes, $by?->name, $this->isStaff($user)));
    }

    /**
     * A one-time link to choose a password: the admin's /reset-password for staff, the API's reset page
     * for customers (the page tells them to sign in in the app afterwards).
     */
    public function setPasswordLink(User $user): string
    {
        $token = Str::random(64);
        PasswordReset::updateOrCreate(['email' => $user->email], [
            'token' => $token,
            'created_at' => now(),
            'expires_at' => now()->addDays(self::WELCOME_LINK_DAYS),
        ]);

        return $this->isStaff($user)
            ? rtrim(config('app.admin_url'), '/') . '/reset-password?token=' . $token
            : url('/api/reset-password') . '?token=' . $token;
    }

    private function isStaff(User $user): bool
    {
        return $user->hasAnyRole(User::STAFF_ROLES);
    }

    private function send(User $user, callable $mailable): void
    {
        if (! $user->email) {
            return;
        }

        try {
            Mail::to($user->email, $user->name)->send($mailable());
        } catch (Throwable $e) {
            Log::warning('Account email to user ' . $user->id . ' failed: ' . $e->getMessage());
        }
    }
}

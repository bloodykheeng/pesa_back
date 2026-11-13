<?php

namespace App\Observers;

use App\Models\User;
use App\Support\Audit\Audit;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sign-ins and sign-outs for the audit trail. Every way in (the app's password and Google
 * sign-ins, the old admin, the new admin) ends by creating a Sanctum token, and signing out deletes
 * it, so watching tokens covers them all without touching the app's controllers.
 */
class SignInObserver
{
    /** Token names => where the person signed in. */
    private const WHERE = ['admin-dashboard' => 'the admin dashboard', 'auth_token' => 'the app'];

    public function created(PersonalAccessToken $token): void
    {
        $user = $token->tokenable;
        if ($user instanceof User) {
            Audit::event('signed_in', $user, 'Signed in to '.$this->where($token, $user), ['remember_until' => $token->expires_at?->toIso8601ZuluString()], $user);
        }
    }

    public function deleted(PersonalAccessToken $token): void
    {
        $user = $token->tokenable;
        // Only a person signing themselves out: tokens removed by a password change go in bulk.
        if ($user instanceof User && auth()->id() === $user->getKey()) {
            Audit::event('signed_out', $user, 'Signed out of '.$this->where($token, $user), [], $user);
        }
    }

    private function where(PersonalAccessToken $token, User $user): string
    {
        if ($token->name === 'auth_token' && $user->hasAnyRole(User::STAFF_ROLES)) {
            return 'the old admin';
        }

        return self::WHERE[$token->name] ?? $token->name;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for the admin dashboard. Staff only (User::STAFF_ROLES): customers sign in through the
 * app with /api/app-login, and are refused here with the same message as a wrong password.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'nullable|boolean',
        ]);

        $key = 'admin-login:' . Str::lower($data['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in ' . ceil(RateLimiter::availableIn($key) / 60) . ' minute(s).',
            ]);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->hasAnyRole(User::STAFF_ROLES)) {
            RateLimiter::hit($key, 600);
            throw ValidationException::withMessages(['email' => 'Wrong email or password.']);
        }

        if ($user->status && $user->status !== 'active') {
            throw ValidationException::withMessages(['email' => 'This account is not active. Ask an administrator.']);
        }

        RateLimiter::clear($key);
        $user->forceFill(['lastlogin' => now()])->save();

        $expiresAt = $request->boolean('remember') ? now()->addDays(30) : now()->addHours(12);
        $token = $user->createToken('admin-dashboard', ['admin'], $expiresAt)->plainTextToken;

        return response()->json([
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * Emails a reset link that opens the dashboard's /reset-password page. The answer is the same
     * whether or not the address belongs to staff, so it cannot be used to find accounts.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $email = $request->validate(['email' => 'required|email'])['email'];
        $answer = response()->json(['message' => 'If that address belongs to a staff account, a reset link is on its way. It works for 60 minutes.']);

        $key = 'admin-forgot:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $answer;
        }
        RateLimiter::hit($key, 900);

        $user = User::where('email', $email)->first();
        if (! $user || ! $user->hasAnyRole(User::STAFF_ROLES)) {
            return $answer;
        }

        $token = Str::random(64);
        PasswordReset::updateOrCreate(['email' => $user->email], ['token' => $token, 'created_at' => now()]);

        $url = rtrim(config('app.admin_url'), '/') . '/reset-password?token=' . $token;
        Mail::send('forgotPasswordMail', ['data' => [
            'url' => $url,
            'email' => $user->email,
            'title' => 'Reset your Elevate Pesa admin password',
            'body' => 'Someone asked to reset the password for this account. If it was you, open the link below within 60 minutes. If not, ignore this email.',
        ]], fn ($message) => $message->to($user->email)->subject('Reset your Elevate Pesa admin password'));

        return $answer;
    }

    private function profile(User $user): array
    {
        $user->loadMissing('roles');

        return (new AdminResource($user))->resolve() + [
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}

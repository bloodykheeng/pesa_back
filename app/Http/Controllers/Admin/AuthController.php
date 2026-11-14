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
    use Concerns\StoresPhotos;

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
            \App\Support\Audit\Audit::event('sign_in_failed', $user, 'Sign-in failed on the admin dashboard', ['login' => $data['email']]);
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
    /** The audit trail's sentence for each reset request (also used by the public PasswordResetController). */
    public const RESET_ASKED = [
        'emailed' => 'Password reset link emailed',
        'no_account' => 'Password reset asked for an address with no account: nothing sent',
        'inactive' => 'Password reset asked for an inactive account: nothing sent',
    ];

    public function forgotPassword(Request $request): JsonResponse
    {
        $email = trim($request->validate(['email' => 'required|email'])['email']);
        // The same answer for every address (it names the one typed, so a typo shows), so nobody can probe
        // which addresses have an account.
        $answer = response()->json([
            'message' => "If {$email} has an Elevate Pesa account, a reset link is on its way. It works for 60 minutes.",
            'email' => $email,
        ]);

        // Over the limit: nothing sent and nothing written, so hammering the form cannot flood the trail.
        $key = 'admin-forgot:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $answer;
        }
        RateLimiter::hit($key, 900);

        // Staff and customers alike: the mobile app's "Forgot password?" opens the admin's /reset-password,
        // and the emailed link saves through the public POST /api/reset-password for anyone.
        // An inactive account cannot sign in, so it gets no link.
        $user = User::where('email', $email)->first();
        $outcome = ! $user ? 'no_account' : (($user->status && $user->status !== 'active') ? 'inactive' : 'emailed');
        \App\Support\Audit\Audit::event('password_reset_requested', $user, self::RESET_ASKED[$outcome], ['login' => $email, 'outcome' => $outcome]);
        if ($outcome !== 'emailed') {
            return $answer;
        }

        $token = Str::random(64);
        // A fresh link replaces any older one, a 7-day welcome link included: it lasts 60 minutes.
        PasswordReset::updateOrCreate(['email' => $user->email], ['token' => $token, 'created_at' => now(), 'expires_at' => null]);

        $title = $user->hasAnyRole(User::STAFF_ROLES) ? 'Reset your Elevate Pesa admin password' : 'Reset your Elevate Pesa password';
        $url = rtrim(config('app.admin_url'), '/') . '/reset-password?token=' . $token;
        Mail::to($user->email)->send(new \App\Mail\ViewMail('forgotPasswordMail', ['data' => [
            'url' => $url,
            'email' => $user->email,
            'title' => $title,
            'body' => 'Someone asked to reset the password for this account. If it was you, open the link below within 60 minutes. If not, ignore this email.',
        ]], $title));

        return $answer;
    }

    /** POST /api/admin/auth/profile: your own name, phone and photo. */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['nullable', 'string', 'max:20', \Illuminate\Validation\Rule::unique('users', 'phone')->ignore($user->id)],
            'photo' => self::PHOTO_RULE,
            'remove_photo' => 'nullable|boolean',
        ]);

        $user->forceFill(['name' => $data['name'], 'phone' => $data['phone'] ?? null]);
        $this->applyPhoto($request, $user, 'user_photos');
        $user->save();

        return response()->json(['message' => 'Your profile is saved.', 'user' => $this->profile($user->fresh())]);
    }

    /** POST /api/admin/auth/password: your own password; every other session is signed out. */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed|different:current_password',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        app(\App\Services\AccountEmails::class)->passwordChanged($user, 'from your profile');

        return response()->json(['message' => 'Your password is changed. Other devices are signed out.']);
    }

    private function profile(User $user): array
    {
        $user->loadMissing('roles');

        return (new AdminResource($user))->resolve() + [
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}

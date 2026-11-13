<?php

namespace App\Http\Controllers;

use App\Models\PasswordReset;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function forgetPassword(Request $request)
    {

        try {
            $user = User::where('email', $request->email)->first();
            // return response()->json(['success' => false, 'message' => 'testing', '$request->email' => $user->email], 404);
            if ($user) {
                $token = Str::random(40);
                $domain = URL::to('/');
                $url = $domain . '/api/reset-password?token=' . $token;

                $data['url'] = $url;
                $data['email'] = $user->email;
                $data['title'] = 'Password Reset';
                $data['body'] = "Please click on the link below to reset your password";

                // Send the password reset email
                Mail::send('forgotPasswordMail', ['data' => $data], function ($message) use ($data) {
                    $message->to($data['email'])->subject($data['title']);
                });

                $datetime = Carbon::now()->format('Y-m-d H:i:s');

                // Update or create the password reset record
                PasswordReset::updateOrCreate(['email' => $user->email], [
                    'email' => $user->email,
                    'token' => $token,
                    'created_at' => $datetime, // Corrected field name to 'created_at'
                ]);

                return response()->json(['success' => true, 'message' => 'Please check your email to reset your password'], 200);
            } else {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    //     handleresetPasswordLoad
    // handlestoringNewPassword

    /** How long a reset link works. */
    public const LINK_MINUTES = 60;

    /**
     * The page the emailed link opens: GET /api/reset-password?token=...
     */
    public function handleResetPasswordLoad(Request $request)
    {
        $reset = $this->validReset($request->query('token'));

        if (! $reset) {
            return response()->view('resetLinkInvalid', [], 410);
        }

        return view('resetPassword', ['token' => $reset->token, 'email' => $reset->email]);
    }

    /**
     * Saves the new password: POST /api/reset-password with token, password, password_confirmation.
     *
     * The user comes from the token, never from the request (it used to trust a posted user id,
     * so anyone could set anyone's password). Answers JSON to JSON callers (the admin dashboard)
     * and HTML to the emailed form.
     */
    public function handlestoringNewPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $reset = $this->validReset($request->input('token'));

        if (! $reset) {
            return $request->expectsJson()
                ? response()->json(['message' => 'This reset link is invalid or has expired. Ask for a new one.'], 410)
                : response()->view('resetLinkInvalid', [], 410);
        }

        if ($validator->fails()) {
            return $request->expectsJson()
                ? response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422)
                : response()->view('resetPassword', ['token' => $reset->token, 'email' => $reset->email, 'errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $reset->email)->first();
        if (! $user) {
            PasswordReset::where('email', $reset->email)->delete();
            return $request->expectsJson()
                ? response()->json(['message' => 'This reset link is invalid or has expired. Ask for a new one.'], 410)
                : response()->view('resetLinkInvalid', [], 410);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();
        $user->tokens()->delete(); // sign out every device that used the old password
        PasswordReset::where('email', $user->email)->delete();

        return $request->expectsJson()
            ? response()->json(['message' => 'Your password has been changed. You can sign in now.'])
            : View::make('passwordResetSuccess');
    }

    private function validReset(?string $token): ?PasswordReset
    {
        if (! $token) {
            return null;
        }

        $reset = PasswordReset::where('token', $token)->first();

        if (! $reset || Carbon::parse($reset->created_at)->lt(now()->subMinutes(self::LINK_MINUTES))) {
            return null;
        }

        return $reset;
    }
}
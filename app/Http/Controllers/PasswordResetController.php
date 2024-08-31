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

    public function handleResetPasswordLoad(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'token' => 'required',
        ]);

        if ($validator->fails()) {
            return view(
                'resetPassword',
                ['errors' => $validator->errors()]
            );
        }

        $resetData = PasswordReset::where('token', $request->query('token'))->first();

        if ($resetData !== null) {

            $user = User::where('email', $resetData['email'])->first();

            if ($user !== null) {
                return view('resetPassword', compact('user'));
            }
        }

        return view('404');
    }

    public function handlestoringNewPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return View::make('resetPassword')->with(['validator' => $validator, 'user' => (object) ['id' => $request->id]]);
        }

        $user = User::find($request->id);
        $user->password = Hash::make($request->password);
        $user->save();

        PasswordReset::where('email', $user->email)->delete();

        return View::make('passwordResetSuccess');
    }
}
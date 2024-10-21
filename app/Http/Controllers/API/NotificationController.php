<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationController extends Controller
{
    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function sendNotification(Request $request)
    {
        // Validate incoming request
        $validated = $request->validate([
            'target' => 'required|string|in:app,email,all', // app, email, or all
            'message' => 'required|string',
        ]);

        // Fetch all users or filtered users as necessary
        $users = User::all(); // Adjust this if targeting specific users

        foreach ($users as $user) {
            // Handle app notifications via Firebase
            if (in_array($validated['target'], ['app', 'all']) && isset($user->device_token)) {
                try {
                    // Attempt to send the notification
                    $this->firebaseService->sendNotification(
                        $user->device_token,
                        'New Notification',
                        $validated['message']
                    );
                } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
                    // Log the error or update the user that their device token is invalid
                    Log::error('Device token not found for user: ' . $user->id . ' - ' . $e->getMessage());

                    // Optionally, you can notify the user to update their app or token
                    continue; // Skip to the next user
                }
            }

            // Handle email notifications
            if (in_array($validated['target'], ['email', 'all']) && $user->email) {
                $emailTemplate = 'emails.generalNotification'; // Create this email template
                Mail::send($emailTemplate, ['notificationMessage' => $validated['message']], function ($message) use ($user) {
                    $message->to($user->email)->subject('New Notification');
                });
            }
        }

        return response()->json(['message' => $validated['message']]);
    }
}

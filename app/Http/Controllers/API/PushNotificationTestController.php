<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FirebaseService;

class PushNotificationTestController extends Controller
{

    protected $firebaseService;


    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function sendPushNotification(Request $request)
    {
        $request->validate([
            'token'=>'required|string',
            'title'=>'required|string',
            'body'=>'required|string',
            'data'=>'nullable|array',
        ]);

        $token = $request->input('token');
        $title = $request->input('title');
        $body = $request->input('body');
        $data = $request->input('data',[]);

        $this->firebaseService->sendNotification($token, $title, $body, $data);

        return response()->json(['message'=>'Notification sent successfully']);
    }

}

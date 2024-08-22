<?php

namespace  App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        // Use storage_path helper to get the full path to the service account JSON file

        $serviceAccountPath = storage_path('firebase-auth.json');

        $factory = (new Factory)->withServiceAccount($serviceAccountPath);
        $this->messaging = $factory->createMessaging();

    }

    public function sendNotification($token, $title, $body, $data=[])
    {
        $messege = CloudMessage::withTarget('token', $token)
         ->withNotification(['title'=>$title, 'body'=>$body])
         ->withData($data);

        $this->messaging->send($messege);
    }
}
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

        // $serviceAccountPath = storage_path('elevate-pesa-firebase-auth.json');
        $serviceAccountPath = [
            'type' => env('GCP_TYPE'),
            'project_id' => env('GCP_PROJECT_ID'),
            'private_key_id' => env('GCP_PRIVATE_KEY_ID'),
            'private_key' => str_replace('\\n', "\n", env('GCP_PRIVATE_KEY')),
            'client_email' => env('GCP_CLIENT_EMAIL'),
            'client_id' => env('GCP_CLIENT_ID'),
            'auth_uri' => env('GCP_AUTH_URI'),
            'token_uri' => env('GCP_TOKEN_URI'),
            'auth_provider_x509_cert_url' => env('GCP_AUTH_PROVIDER_CERT_URL'),
            'client_x509_cert_url' => env('GCP_CLIENT_CERT_URL'),
            'universe_domain' => env('GCP_UNIVERSE_DOMAIN'),
        ];

        $factory = (new Factory)->withServiceAccount($serviceAccountPath);
        $this->messaging = $factory->createMessaging();
    }

    public function sendNotification($token, $title, $body, $data = [])
    {
        $messege = CloudMessage::withTarget('token', $token)
            ->withNotification(['title' => $title, 'body' => $body])
            ->withData($data);

        $this->messaging->send($messege);
    }

    public function sendNotificationTopic($title, $body, $data = [])
    {

        try {
            $messege = CloudMessage::withTarget('topic', 'elevatePesa')
                ->withNotification(['title' => $title, 'body' => $body])
                ->withData($data);

            $this->messaging->send($messege);
        } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
            // Log the error for debugging purposes

            // Optionally, notify the user about the issue via email or other means
            // ...
        }
    }
}

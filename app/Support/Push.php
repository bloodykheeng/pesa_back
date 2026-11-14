<?php

namespace App\Support;

use App\Jobs\SendPush;

/**
 * Send a push message to a phone without making the request wait: queued (App\Jobs\SendPush), and only once the
 * current database transaction has committed, so a rolled-back order never announces itself. No token, no push.
 */
final class Push
{
    /** @param  array<string, string>  $data */
    public static function send(?string $token, string $title, string $body, array $data = []): bool
    {
        if (! $token) {
            return false;
        }
        SendPush::dispatch($token, $title, $body, $data)->afterCommit();

        return true;
    }

    /**
     * Every phone with the app (the "elevatePesa" topic), queued the same way.
     *
     * @param  array<string, string>  $data
     */
    public static function everyone(string $title, string $body, array $data = []): void
    {
        SendPush::dispatch(null, $title, $body, $data)->afterCommit();
    }
}

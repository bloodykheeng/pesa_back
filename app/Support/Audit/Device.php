<?php

namespace App\Support\Audit;

/**
 * A request's user agent as people say it: "Chrome on Windows", "The app on Android".
 *
 * Small on purpose (no package): the audit trail needs the kind of device, the system and the
 * browser, not a full device database. Stored with each entry at write time (`request.device`)
 * and worked out again on read for entries written before it existed.
 */
class Device
{
    /** @return array{kind: string, platform: ?string, browser: ?string, summary: string} */
    public static function describe(?string $userAgent): array
    {
        $ua = (string) $userAgent;
        if ($ua === '') {
            return ['kind' => 'Unknown', 'platform' => null, 'browser' => null, 'summary' => 'Unknown device'];
        }

        $platform = self::platform($ua);
        $browser = self::browser($ua);
        $phone = (bool) preg_match('/Mobile|Android|iPhone|iPod|Dart|okhttp|CFNetwork/i', $ua);
        $tablet = (bool) preg_match('/iPad|Tablet/i', $ua) || (str_contains($ua, 'Android') && ! str_contains($ua, 'Mobile') && $browser !== 'The app');
        $kind = $tablet ? 'Tablet' : ($phone ? 'Phone' : (preg_match('/curl|Postman|Guzzle|python|Symfony/i', $ua) ? 'Script' : 'Computer'));

        $summary = match (true) {
            $browser === 'The app' => 'The app'.($platform ? " on {$platform}" : ''),
            $kind === 'Script' => 'A script ('.($browser ?? 'no browser').')',
            default => trim(($browser ?? 'A browser').($platform ? " on {$platform}" : '')),
        };

        return ['kind' => $kind, 'platform' => $platform, 'browser' => $browser, 'summary' => $summary];
    }

    private static function platform(string $ua): ?string
    {
        return match (true) {
            (bool) preg_match('/Android ([\d.]+)/', $ua, $m) => 'Android '.explode('.', $m[1])[0],
            str_contains($ua, 'Android') || str_contains($ua, 'okhttp') => 'Android',
            (bool) preg_match('/(iPhone|iPad|iPod).*OS (\d+)/', $ua, $m) => "{$m[1]} (iOS {$m[2]})",
            str_contains($ua, 'CFNetwork') || str_contains($ua, 'Darwin') && ! str_contains($ua, 'Macintosh') => 'iPhone',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };
    }

    private static function browser(string $ua): ?string
    {
        return match (true) {
            // The mobile app's HTTP clients (Flutter, React Native on Android and iOS).
            (bool) preg_match('/Dart\/|okhttp|CFNetwork|Expo/i', $ua) => 'The app',
            (bool) preg_match('/Edg\/(\d+)/', $ua, $m) => "Edge {$m[1]}",
            (bool) preg_match('/OPR\/(\d+)/', $ua, $m) => "Opera {$m[1]}",
            (bool) preg_match('/SamsungBrowser\/(\d+)/', $ua, $m) => "Samsung Internet {$m[1]}",
            (bool) preg_match('/(?:Chrome|CriOS)\/(\d+)/', $ua, $m) => "Chrome {$m[1]}",
            (bool) preg_match('/(?:Firefox|FxiOS)\/(\d+)/', $ua, $m) => "Firefox {$m[1]}",
            (bool) preg_match('/Version\/(\d+).*Safari/', $ua, $m) => "Safari {$m[1]}",
            (bool) preg_match('/^(curl|PostmanRuntime|GuzzleHttp|python-requests|Symfony)/i', $ua, $m) => $m[1],
            default => null,
        };
    }
}

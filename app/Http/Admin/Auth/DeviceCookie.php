<?php

/**
 * Marks a browser that has signed in to an account before, so strangers
 * failing on that account never lock its owner out. Laravel's cookie
 * encryption also authenticates the value; it holds keyed hashes of email
 * addresses, never the addresses. OWASP's device-cookie protocol; study G, S4.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie as CookieJar;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final class DeviceCookie
{
    public const string NAME = 'teme_device';

    private const int MAX_ACCOUNTS = 5;

    private const int LIFETIME_MINUTES = 60 * 24 * 365;

    public static function recognises(Request $request, string $email): bool
    {
        return in_array(self::fingerprint($email), self::fingerprints($request), true);
    }

    /**
     * The cookie value for a browser that has just signed in as $email,
     * keeping the other accounts it already knew.
     */
    public static function valueFor(string $email, ?Request $request = null): string
    {
        $known = $request === null ? [] : self::fingerprints($request);
        $fingerprints = array_values(array_unique([self::fingerprint($email), ...$known]));

        return (string) json_encode(array_slice($fingerprints, 0, self::MAX_ACCOUNTS));
    }

    public static function make(string $email, Request $request): Cookie
    {
        return CookieJar::make(
            name: self::NAME,
            value: self::valueFor($email, $request),
            minutes: self::LIFETIME_MINUTES,
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    private static function fingerprint(string $email): string
    {
        return hash_hmac('sha256', Str::lower(trim($email)), (string) config('app.key'));
    }

    /**
     * @return list<string>
     */
    private static function fingerprints(Request $request): array
    {
        $decoded = json_decode((string) $request->cookie(self::NAME, ''), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie as CookieJar;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The `teme_auth` marker a signed-in browser carries (I-262): set at every
 * sign-in, cleared at sign-out. Its presence alone sends a reader past the
 * page cache and the prerendering, and has core's script ask who is
 * reading; nothing reads a session to know it.
 */
final class SignedInMarker
{
    public const string COOKIE = 'teme_auth';

    public static function present(Request $request): bool
    {
        return $request->cookies->has(self::COOKIE);
    }

    /**
     * The marker set at sign-in, kept until the browser closes or the
     * person signs out. Readable by scripts (I-465): core's script on a
     * public page asks /_teme/session only in a browser that carries it. It
     * holds nothing to steal; only its presence is read, by the server and
     * the script alike.
     */
    public static function cookie(): Cookie
    {
        return CookieJar::make(name: self::COOKIE, value: '1', minutes: 0, httpOnly: false, sameSite: 'lax');
    }

    /**
     * The marker's removal, sent at sign-out.
     */
    public static function expired(): Cookie
    {
        return CookieJar::forget(self::COOKIE);
    }
}

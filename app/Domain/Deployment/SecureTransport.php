<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

/**
 * Whether a site is reached over https alone (I-380): in production, at an
 * https APP_URL. Then its cookies are Secure, its session cookie is a
 * __Host- cookie, and every https answer carries Strict-Transport-Security.
 */
final class SecureTransport
{
    /** The header's value unless TEME_HSTS says otherwise: a year, this host only, never preloaded. */
    public const string DEFAULT_HSTS = 'max-age=31536000';

    /** A value Teme sends: max-age, then includeSubDomains and preload if given, in that order. */
    public const string HSTS_FORM = '/^max-age=\d{1,10}(;\s*includeSubDomains)?(;\s*preload)?$/i';

    public static function required(bool $production, string $url): bool
    {
        return $production && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /**
     * The Strict-Transport-Security value for an https answer, or null when
     * none is sent: outside production, at an http APP_URL, or with TEME_HSTS empty.
     */
    public static function hsts(bool $production, string $url, string $configured): ?string
    {
        $value = trim($configured);

        return $value !== '' && self::required($production, $url) ? $value : null;
    }
}

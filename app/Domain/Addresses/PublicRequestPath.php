<?php

/**
 * A public request's path in the form addresses are stored in: URL-decoded,
 * NFC-normalised, lower-cased, repeated slashes collapsed, and no leading or
 * trailing slash ('' for the home page).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses;

use Normalizer;

final class PublicRequestPath
{
    /**
     * The stored form of a raw request path, such as `/Caf%C3%A9//Menu/`.
     */
    public static function normalise(string $rawPath): string
    {
        $decoded = rawurldecode($rawPath);
        $normalised = Normalizer::normalize($decoded, Normalizer::FORM_C);

        return trim((string) preg_replace('#/{2,}#', '/', mb_strtolower($normalised === false ? $decoded : $normalised)), '/');
    }

    /**
     * A site-relative URL as it goes in a Location header: each segment
     * percent-encoded, so a Unicode slug travels safely.
     */
    public static function encode(string $url): string
    {
        return implode('/', array_map(fn (string $segment): string => rawurlencode($segment), explode('/', $url)));
    }
}

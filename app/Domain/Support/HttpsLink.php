<?php

/**
 * The one check a link read from a package's file passes before it reaches
 * an href: an https address of at most 2000 characters, or nothing.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Support;

final class HttpsLink
{
    public const int MOST = 2000;

    /**
     * Returns the address when it is a valid https address, else ''.
     */
    public static function or(string $value): string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > self::MOST || ! str_starts_with(strtolower($value), 'https://')) {
            return '';
        }

        return filter_var($value, FILTER_VALIDATE_URL) === false ? '' : $value;
    }
}

<?php

/**
 * The password hash formats Teme can verify, each recognised by its exact
 * shape. Teme writes only Argon2id; the others verify until the person's
 * next sign-in replaces them (I-124). Any other shape is no format, and never
 * verifies.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Security;

enum PasswordHashFormat: string
{
    case Argon2id = 'argon2id';
    case Bcrypt = 'bcrypt';
    case WordPressBcrypt = 'wordpress-bcrypt';
    case Phpass = 'phpass';
    case Md5 = 'md5';

    /**
     * The format of a stored hash, or null for one Teme can't verify: its
     * length first, then its prefix, in WordPress's own order.
     */
    public static function detect(string $hash): ?self
    {
        if (strlen($hash) <= 32) {
            return preg_match('/\A[0-9a-f]{32}\z/', $hash) === 1 ? self::Md5 : null;
        }

        return match (true) {
            preg_match('/\A\$wp\$2y\$(0[4-9]|1[0-6])\$[.\/0-9A-Za-z]{53}\z/', $hash) === 1 => self::WordPressBcrypt,
            preg_match('/\A\$P\$[5-9A-E][.\/0-9A-Za-z]{30}\z/', $hash) === 1 => self::Phpass,
            str_starts_with($hash, '$argon2id$') => self::Argon2id,
            preg_match('/\A\$2y\$(0[4-9]|1[0-6])\$[.\/0-9A-Za-z]{53}\z/', $hash) === 1 => self::Bcrypt,
            default => null,
        };
    }
}

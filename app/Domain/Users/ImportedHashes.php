<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Domain\Users\Security\PasswordHashFormat;

/**
 * Which password hashes an import keeps as they are: those Teme can check
 * at sign-in, an Argon2id one only in PHP's exact form and at Teme's own
 * costs or below (I-1761), since its costs are what every sign-in attempt
 * for that account will spend.
 */
final class ImportedHashes
{
    /** The formats Teme never writes itself: a person whose stored hash is one of them hasn't signed in or reset here. */
    public const array IMPORTED_FORMATS = [PasswordHashFormat::Md5, PasswordHashFormat::Phpass, PasswordHashFormat::WordPressBcrypt, PasswordHashFormat::Bcrypt];

    /**
     * The hash itself when an import may keep it; null when the person
     * must reset their password instead.
     */
    public static function usable(string $hash): ?string
    {
        $format = PasswordHashFormat::detect($hash);

        if ($format !== PasswordHashFormat::Argon2id) {
            return $format === null ? null : $hash;
        }

        if (preg_match('/\A\$argon2id\$v=19\$m=(\d{1,10}),t=(\d{1,10}),p=(\d{1,3})\$[A-Za-z0-9+\/]{11,}\$[A-Za-z0-9+\/]{16,}\z/', $hash, $costs) !== 1) {
            return null;
        }

        return (int) $costs[1] <= (int) config('hashing.argon.memory')
            && (int) $costs[2] <= (int) config('hashing.argon.time')
            && (int) $costs[3] <= (int) config('hashing.argon.threads')
                ? $hash
                : null;
    }
}

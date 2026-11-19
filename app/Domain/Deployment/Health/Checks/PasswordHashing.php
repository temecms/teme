<?php

/**
 * Times one password hash at the configured cost (I-382): 250 to 500 ms
 * makes a stolen hash dear to crack without holding a PHP worker for long.
 * Too quick or too slow is a warning, never an error.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final readonly class PasswordHashing implements DoctorCheck
{
    /** Milliseconds a hash should take at least. */
    public const int FASTEST = 250;

    /** Milliseconds a hash should take at most. */
    public const int SLOWEST = 500;

    public function title(): string
    {
        return 'Password hashing';
    }

    public function examine(): array
    {
        if (! defined('PASSWORD_ARGON2ID')) {
            return [new Finding(Severity::Error, 'This PHP has no Argon2id, so no password can be hashed.', "Use a PHP built with Argon2 support, as the image's is.")];
        }

        $start = hrtime(true);
        Hash::make(Str::random(40));
        $milliseconds = (int) round((hrtime(true) - $start) / 1_000_000);

        if (self::verdict($milliseconds) === Severity::Ok) {
            return [new Finding(Severity::Ok, "Hashing one password takes {$milliseconds} ms.")];
        }

        if ($milliseconds < self::FASTEST) {
            return [new Finding(Severity::Warning, "Hashing one password took {$milliseconds} ms, under the 250 to 500 ms Teme aims for, so a stolen hash is cheaper to crack.", 'Raise ARGON_TIME, or ARGON_MEMORY if the server has the memory, until it takes 250 to 500 ms.')];
        }

        return [new Finding(Severity::Warning, "Hashing one password took {$milliseconds} ms, over the 250 to 500 ms Teme aims for: every sign-in holds a PHP worker that long.", 'Lower ARGON_TIME, then ARGON_MEMORY, and keep AUTH_TIMEBOX_DURATION above the time it takes.')];
    }

    /**
     * Ok from FASTEST to SLOWEST milliseconds inclusive, a warning outside.
     */
    public static function verdict(int $milliseconds): Severity
    {
        return $milliseconds >= self::FASTEST && $milliseconds <= self::SLOWEST ? Severity::Ok : Severity::Warning;
    }
}

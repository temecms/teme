<?php

/**
 * Whether the host backs the site up itself (TEME_BACKUPS=host) or Teme does
 * (teme, the default). Under host, Teme asks for nothing it doesn't need: no
 * spare database, no amber verdict for a backup the host already makes. An
 * unknown value stops the start, as other settings do (StartRules).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

final class BackupMode
{
    public const string TEME = 'teme';

    public const string HOST = 'host';

    /**
     * The sentence that refuses the start when a TEME_BACKUPS value is one
     * Teme doesn't know, or null when it is teme, host or nothing (which is teme).
     */
    public static function problemFor(mixed $value): ?string
    {
        $configured = self::normalise($value);

        if ($configured === '' || in_array($configured, [self::TEME, self::HOST], true)) {
            return null;
        }

        return "TEME_BACKUPS is “{$configured}”, which Teme doesn’t know. Use teme or host.";
    }

    /**
     * Whether TEME_BACKUPS is host: the host backs up and tests the restores.
     */
    public static function isHost(): bool
    {
        return self::normalise(config('teme.backup_mode')) === self::HOST;
    }

    /**
     * TEME_BACKUPS as written, trimmed and in lower case; empty when not set.
     */
    private static function normalise(mixed $value): string
    {
        return is_scalar($value) ? strtolower(trim((string) $value)) : '';
    }
}

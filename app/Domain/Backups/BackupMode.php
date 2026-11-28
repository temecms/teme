<?php

/**
 * Whether the host backs the site up itself (TEME_BACKUPS=host) or Teme does
 * (teme, the default). Under host, Teme asks for nothing it doesn't need: no
 * spare database, no amber verdict for a backup the host already makes. An
 * unknown value stops the start, as other settings do.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use InvalidArgumentException;

final class BackupMode
{
    public const string TEME = 'teme';

    public const string HOST = 'host';

    /**
     * Stops the start when TEME_BACKUPS holds a value Teme doesn't know.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValid(): void
    {
        $configured = self::configured();

        if (! in_array($configured, [self::TEME, self::HOST], true)) {
            throw new InvalidArgumentException("TEME_BACKUPS is “{$configured}”, which Teme doesn’t know. Use teme or host.");
        }
    }

    /**
     * Whether TEME_BACKUPS is host: the host backs up and tests the restores.
     */
    public static function isHost(): bool
    {
        return self::configured() === self::HOST;
    }

    /**
     * TEME_BACKUPS as written, trimmed and in lower case.
     */
    private static function configured(): string
    {
        return strtolower(trim((string) config('teme.backup_mode', self::TEME)));
    }
}

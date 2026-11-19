<?php

/**
 * Whether a folder's disk has room for what a restore writes there, in words
 * with sizes people read, written without PHP's intl, which Teme doesn't
 * require.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

final class RestoreRoom
{
    /**
     * The problem, or null when there's room or the disk won't say.
     */
    public static function problem(int $need, int|float|false $free, string $folder, string $what): ?string
    {
        if ($free === false || $free >= $need) {
            return null;
        }

        return $what === 'database'
            ? 'There isn’t room for the copy of the database: it needs '.self::size($need)." in {$folder}, and ".self::size($free).' is free.'
            : 'There isn’t room for the media: it needs about '.self::size($need)." more in {$folder}, and ".self::size($free).' is free.';
    }

    /**
     * A size as Laravel's Number::fileSize() writes it, one decimal from KB up.
     */
    public static function size(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $unit = 0;

        while ($bytes / 1024 > 0.9 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return ($unit === 0 ? number_format($bytes) : number_format($bytes, 1)).' '.$units[$unit];
    }
}

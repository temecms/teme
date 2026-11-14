<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use RuntimeException;

/**
 * Thrown when another deploy, or a backup, still held its lock after the
 * longest wait teme:deploy was given; nothing has changed.
 */
final class DeployBusy extends RuntimeException
{
    public static function lock(int $seconds): self
    {
        return new self('Another deploy still held the lock after '.self::seconds($seconds).', so nothing was changed. Run php artisan teme:deploy again once it has finished.');
    }

    public static function backup(int $seconds): self
    {
        return new self('A backup still held the backup lock after '.self::seconds($seconds).', so nothing was changed. Run php artisan teme:deploy again once it has finished.');
    }

    private static function seconds(int $seconds): string
    {
        return $seconds === 1 ? '1 second' : "{$seconds} seconds";
    }
}

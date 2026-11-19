<?php

/**
 * How serious a doctor's finding is, in Drupal's four levels and order
 * (I-157): only an error makes teme:doctor fail.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

enum Severity: int
{
    case Info = -1;
    case Ok = 0;
    case Warning = 1;
    case Error = 2;

    /**
     * Error on a production site and a warning anywhere else: for what only
     * production must have.
     */
    public static function inProduction(bool $production): self
    {
        return $production ? self::Error : self::Warning;
    }

    public function label(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Ok => 'ok',
            self::Warning => 'warning',
            self::Error => 'error',
        };
    }
}

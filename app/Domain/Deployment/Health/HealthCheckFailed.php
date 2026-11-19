<?php

/**
 * Why /up failed (I-378): every problem the check found, one a line, for
 * the log alone. Laravel's route reports it and never shows it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

use RuntimeException;

final class HealthCheckFailed extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public static function because(array $problems): self
    {
        return new self("The health check failed:\n- ".implode("\n- ", $problems));
    }
}

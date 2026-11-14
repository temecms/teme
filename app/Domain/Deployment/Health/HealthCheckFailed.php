<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

use RuntimeException;

/**
 * Why /up failed (I-378): every problem the check found, one a line, for
 * the log alone. Laravel's route reports it and never shows it.
 */
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

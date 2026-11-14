<?php

declare(strict_types=1);

namespace App\Domain\Settings\Exceptions;

use RuntimeException;

/**
 * Thrown when a settings form was opened on an older version of the group, so
 * saving it would silently undo someone else's change.
 */
final class StaleSettingsException extends RuntimeException
{
    public static function for(string $group, int $expected, int $actual): self
    {
        return new self("The [{$group}] settings changed since this form was opened (version {$expected}, now {$actual}).");
    }
}

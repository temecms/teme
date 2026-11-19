<?php

/**
 * Thrown when a settings form was opened on an older version of the group, so
 * saving it would silently undo someone else's change.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings\Exceptions;

use RuntimeException;

final class StaleSettingsException extends RuntimeException
{
    public static function for(string $group, int $expected, int $actual): self
    {
        return new self("The [{$group}] settings changed since this form was opened (version {$expected}, now {$actual}).");
    }
}

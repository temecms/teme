<?php

/**
 * Thrown when a draft is saved from an older revision than the current one, so
 * the save would silently overwrite a newer edit.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Exceptions;

use RuntimeException;

final class StaleRevisionException extends RuntimeException
{
    public static function for(string $expected, ?string $actual): self
    {
        return new self(
            "This content changed since you loaded it. Expected revision {$expected}, found ".($actual ?? 'none').'.'
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Types\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when code asks the content-type registry for a type nobody
 * registered.
 */
final class UnknownContentType extends InvalidArgumentException
{
    public static function named(string $key): self
    {
        return new self("No content type [{$key}] is registered.");
    }
}

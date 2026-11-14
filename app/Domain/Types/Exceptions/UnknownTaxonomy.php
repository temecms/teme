<?php

declare(strict_types=1);

namespace App\Domain\Types\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when code asks the taxonomy registry for a taxonomy nobody
 * registered.
 */
final class UnknownTaxonomy extends InvalidArgumentException
{
    public static function named(string $key): self
    {
        return new self("No taxonomy [{$key}] is registered.");
    }
}

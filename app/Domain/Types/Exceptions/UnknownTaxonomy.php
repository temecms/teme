<?php

/**
 * Thrown when code asks the taxonomy registry for a taxonomy nobody
 * registered.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Exceptions;

use InvalidArgumentException;

final class UnknownTaxonomy extends InvalidArgumentException
{
    public static function named(string $key): self
    {
        return new self("No taxonomy [{$key}] is registered.");
    }
}

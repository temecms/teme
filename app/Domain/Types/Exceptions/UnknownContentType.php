<?php

/**
 * Thrown when code asks the content-type registry for a type nobody
 * registered.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Exceptions;

use InvalidArgumentException;

final class UnknownContentType extends InvalidArgumentException
{
    public static function named(string $key): self
    {
        return new self("No content type [{$key}] is registered.");
    }
}

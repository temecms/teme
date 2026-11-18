<?php

/**
 * Thrown when a type's definition can't be read: wrong shape, wrong types,
 * or a key a version 1 type doesn't have.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Exceptions;

use InvalidArgumentException;

final class InvalidTypeDefinition extends InvalidArgumentException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}

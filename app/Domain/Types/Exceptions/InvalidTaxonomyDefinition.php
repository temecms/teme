<?php

declare(strict_types=1);

namespace App\Domain\Types\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a taxonomy's definition can't be read: wrong shape, wrong
 * types, or a key a version 1 taxonomy doesn't have.
 */
final class InvalidTaxonomyDefinition extends InvalidArgumentException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}

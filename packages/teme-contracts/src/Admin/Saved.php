<?php

declare(strict_types=1);

namespace Teme\Contracts\Admin;

use InvalidArgumentException;

/**
 * What a write did: the record's id, and whether anything changed, so core
 * purges the pages it touches only then.
 *
 * @since 1.0.0
 */
final readonly class Saved
{
    /**
     * @throws InvalidArgumentException for an empty or over-long id
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $id,
        public bool $changed = true,
    ) {
        if ($id === '' || strlen($id) > 64) {
            throw new InvalidArgumentException('A saved record’s id must be 1 to 64 characters.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

use RuntimeException;

/**
 * A list-column call that took longer than the per-call budget.
 */
final class ColumnTooSlow extends RuntimeException
{
    public function __construct(string $key, int $milliseconds)
    {
        parent::__construct("List column {$key} took {$milliseconds} ms.");
    }
}

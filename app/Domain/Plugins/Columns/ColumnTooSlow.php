<?php

/**
 * A list-column call that took longer than the per-call budget.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

use RuntimeException;

final class ColumnTooSlow extends RuntimeException
{
    public function __construct(string $key, int $milliseconds)
    {
        parent::__construct("List column {$key} took {$milliseconds} ms.");
    }
}

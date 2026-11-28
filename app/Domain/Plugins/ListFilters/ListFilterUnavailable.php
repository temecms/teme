<?php

/**
 * A list filter whose table or columns are gone since its plugin was enabled:
 * recorded under the plugin as a fault, and the filter dropped from the list.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\ListFilters;

use RuntimeException;

final class ListFilterUnavailable extends RuntimeException
{
    public function __construct(string $key, string $table)
    {
        parent::__construct("List filter {$key} reads {$table}, which is no longer there.");
    }
}

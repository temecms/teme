<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Columns;

use RuntimeException;
use Teme\Contracts\Lists\ListRows;
use Tests\Fixtures\Plugins\ListColumnCalls;

/**
 * Shared mode switches for the columns fixture's handlers.
 */
final class ColumnMode
{
    public static function apply(string $mode, ListRows $rows, bool $mayThrow = false): void
    {
        ListColumnCalls::record($rows);

        if ($mayThrow && $mode === 'throw') {
            throw new RuntimeException('The columns fixture threw on purpose.');
        }
    }
}

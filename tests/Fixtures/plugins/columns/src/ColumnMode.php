<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Columns;

use RuntimeException;
use Teme\Contracts\Lists\ListRows;

/**
 * Shared mode switches for the columns fixture's handlers.
 */
final class ColumnMode
{
    /** @var list<ListRows> */
    public static array $seen = [];

    public static function reset(): void
    {
        self::$seen = [];
    }

    public static function apply(string $mode, ListRows $rows): void
    {
        self::$seen[] = $rows;

        if ($mode === 'throw') {
            throw new RuntimeException('The columns fixture threw on purpose.');
        }

        if ($mode === 'slow') {
            // The fake ColumnClock advances across this call in the tests.
            usleep(0);
        }
    }
}

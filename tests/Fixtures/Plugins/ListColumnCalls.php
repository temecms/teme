<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins;

use Teme\Contracts\Lists\ListRows;

/**
 * Records ListRows each columns-fixture handler was asked with, so the
 * list-column tests can assert batching and order without depending on
 * the plugin folder's autoload.
 */
final class ListColumnCalls
{
    /** @var list<ListRows> */
    public static array $seen = [];

    public static function reset(): void
    {
        self::$seen = [];
    }

    public static function record(ListRows $rows): void
    {
        self::$seen[] = $rows;
    }
}

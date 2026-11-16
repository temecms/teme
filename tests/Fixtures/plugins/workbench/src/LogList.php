<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Workbench;

use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\PluginList;

/**
 * The workbench's log: what the test set, else one row of each kind.
 */
final class LogList implements PluginList
{
    public function rows(int $limit): array
    {
        Workbench::$asked[] = $limit;

        if (Workbench::$rows !== null) {
            /** @var list<array<string, Cell>> */
            return (Workbench::$rows)($limit);
        }

        return [['address' => Cell::text('https://example.com/'), 'sent' => Cell::number(3), 'answer' => Cell::badge('Accepted', 'ok')]];
    }
}

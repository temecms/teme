<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

/**
 * What PluginColumns kept of one page's shown columns: the cells by column
 * key and row id, and the plugin labels whose columns ended empty.
 */
final readonly class ColumnCells
{
    /**
     * @param  array<string, array<string, array{kind: string, value: string|int|float, tone?: string}>>  $values
     * @param  list<string>  $problems
     */
    public function __construct(
        public array $values,
        public array $problems,
    ) {}
}

<?php

declare(strict_types=1);

namespace Teme\Contracts\Admin;

use Teme\Contracts\Content\WriteRefused;

/**
 * The data behind a plugin's area, and its writes. Core calls it, under a
 * time budget, and draws every screen itself; the plugin never draws.
 *
 * @since 0.9.0
 */
interface PluginArea
{
    /**
     * A figures block's figures, by declared item id; a missing item shows "—".
     *
     * @return array<string, Figure>
     *
     * @since 0.9.0
     */
    public function figures(string $page, string $block, AreaContext $context): array;

    /**
     * A chart block's labels and series.
     *
     * @since 0.9.0
     */
    public function chart(string $page, string $block, AreaContext $context): Chart;

    /**
     * One page of a record's rows, as the query asks.
     *
     * @since 0.9.0
     */
    public function rows(string $record, TableQuery $query, AreaContext $context): RowPage;

    /**
     * A record's stored values by field key, or null when there is no such record.
     *
     * @return array<string, mixed>|null
     *
     * @since 0.9.0
     */
    public function values(string $record, string $id, AreaContext $context): ?array;

    /**
     * Stores a record core has already checked, inside core's transaction.
     *
     * @param  array<string, mixed>  $values  only fields that are shown; a hidden field is left out and must be left as it is
     *
     * @throws WriteRefused with one plain sentence; nothing is kept
     *
     * @since 0.9.0
     */
    public function save(string $record, ?string $id, array $values, AreaContext $context): Saved;

    /**
     * Runs a built-in row action on these records: duplicate, enable, disable or delete.
     *
     * @param  list<string>  $ids
     *
     * @throws WriteRefused with one plain sentence; nothing is kept
     *
     * @since 0.9.0
     */
    public function act(string $record, string $action, array $ids, AreaContext $context): Saved;
}

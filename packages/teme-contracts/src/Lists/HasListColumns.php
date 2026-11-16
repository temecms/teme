<?php

declare(strict_types=1);

namespace Teme\Contracts\Lists;

/**
 * A plugin with list columns: its handlers, by declared column id.
 * Implemented beside Plugin.
 *
 * @since 0.9.0
 */
interface HasListColumns
{
    /**
     * The handlers for the plugin's declared list columns, keyed by column id.
     *
     * @return array<string, ListColumn>
     *
     * @since 0.9.0
     */
    public function listColumns(): array;
}

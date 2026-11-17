<?php

declare(strict_types=1);

namespace Teme\Contracts\Lists;

/**
 * A plugin with lists on its settings page, by declared id. Implemented
 * beside Plugin.
 *
 * @since 1.0.0
 */
interface HasPluginLists
{
    /**
     * The plugin's declared lists, keyed by list id.
     *
     * @return array<string, PluginList>
     *
     * @since 1.0.0
     */
    public function pluginLists(): array;
}

<?php

declare(strict_types=1);

namespace Teme\Contracts\Admin;

/**
 * A plugin whose manifest declares an area: its own admin pages, which core
 * draws from the data it returns.
 *
 * @since 0.9.0
 */
interface HasPluginArea
{
    /** @since 0.9.0 */
    public function pluginArea(): PluginArea;
}

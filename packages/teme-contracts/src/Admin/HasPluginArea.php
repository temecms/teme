<?php

declare(strict_types=1);

namespace Teme\Contracts\Admin;

/**
 * A plugin whose manifest declares an area: its own admin pages, which core
 * draws from the data it returns.
 *
 * @since 1.0.0
 */
interface HasPluginArea
{
    /** @since 1.0.0 */
    public function pluginArea(): PluginArea;
}

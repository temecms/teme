<?php

declare(strict_types=1);

namespace Teme\Contracts\Plugins;

use Teme\Contracts\Hooks\Hooks;

/**
 * A plugin's own class, the one its teme-plugin.json names under `plugin`.
 * Teme builds it with only what its constructor asks for and Teme offers
 * plugins, never from the container, and only once someone has enabled it.
 *
 * @since 0.5.0
 */
interface Plugin
{
    /**
     * Teme calls it once a process, on every page but its safe ones, after
     * building the plugin with what it asked for. Subscribe here, and do
     * nothing slow.
     *
     * @since 0.5.0
     */
    public function register(Hooks $hooks): void;
}

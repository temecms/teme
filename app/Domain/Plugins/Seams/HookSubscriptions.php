<?php

/**
 * Each plugin's own Hooks, and the subscriptions it made (I-3353):
 * DispatcherSubscriptions gives the dispatcher's own.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seams;

use Teme\Contracts\Hooks\Hooks;

interface HookSubscriptions
{
    /**
     * This plugin's own Hooks: its granted capabilities, its declared hooks
     * by short name, and its quarantined subscription ids.
     *
     * @param  list<string>  $capabilities
     * @param  list<string>  $declared
     * @param  list<string>  $quarantined
     */
    public function for(string $plugin, array $capabilities, array $declared, array $quarantined): Hooks;

    /** Removes every subscription this plugin made, after it failed to start. */
    public function forget(string $plugin): void;

    /** Ends this plugin's register(): a later on() or off() is refused. */
    public function close(Hooks $hooks): void;
}

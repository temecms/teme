<?php

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * Sends the calling plugin's own declared hooks to the plugins that listen:
 * each a class in the plugin's own namespace, named in its manifest's
 * `contributes.hooks`. Listeners are called as for Teme's own hooks: a
 * listener's failure, the order and the failure rules are the same.
 *
 * @since 1.0.0
 */
interface HookSender
{
    /**
     * Tells every listener; waits for the outermost commit when a
     * transaction is open, as Teme's own notifications do, and is never
     * told if it rolls back.
     *
     * @throws HookRefused for a hook this plugin doesn't declare
     *
     * @since 1.0.0
     */
    public function notify(Notification $hook): void;

    /**
     * Returns the value as the last good listener left it.
     *
     * @throws HookRefused for a hook this plugin doesn't declare
     *
     * @since 1.0.0
     */
    public function transform(Transform $hook): mixed;

    /**
     * Returns every good item, in listener order.
     *
     * @return list<mixed>
     *
     * @throws HookRefused for a hook this plugin doesn't declare
     *
     * @since 1.0.0
     */
    public function collect(Collect $hook): array;
}

<?php

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * How a plugin listens: each plugin gets its own, bound to its name, what
 * it was granted and the hooks it declared, open only while its register()
 * runs. A subscription is removed by its handle, never by its listener.
 *
 * @since 0.5.0
 */
interface Hooks
{
    /**
     * Subscribes a listener to a hook, heard from the next dispatch.
     *
     * @template H of Hook
     *
     * @param  class-string<H>  $hook
     * @param  callable  $listener  as the hook's verb says
     *
     * @throws HookRefused when the class isn't a hook, the plugin didn't declare it or wasn't granted its capability, or register() is over
     *
     * @since 0.5.0
     */
    public function on(string $hook, callable $listener, ?Order $order = null): Subscription;

    /**
     * Removes a subscription this plugin made, while register() runs;
     * anything else is ignored.
     *
     * @since 0.5.0
     */
    public function off(Subscription $subscription): void;
}

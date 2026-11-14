<?php

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * A hook that gathers items from every listener. A listener is
 * `fn (TheHook $hook): list<mixed>`; every good item is kept, in listener
 * order, and a listener that fails or gives an item of the wrong type adds
 * none of its items.
 *
 * @since 0.5.0
 */
interface Collect extends Hook
{
    /**
     * The PHP type each item must have: string, int, float, bool, array,
     * list, or a class or interface, optionally prefixed with `?`.
     *
     * @since 0.5.0
     */
    public static function itemType(): string;
}

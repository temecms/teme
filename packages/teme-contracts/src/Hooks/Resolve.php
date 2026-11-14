<?php

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * A hook that asks listeners for one answer. A listener is
 * `fn (TheHook $hook): mixed` and returns an answer or null; the first
 * answer wins, and a listener that fails before one comes leaves none.
 *
 * @since 0.5.0
 */
interface Resolve extends Hook
{
    /**
     * The PHP type the answer must have: string, int, float, bool, array,
     * list, or a class or interface, optionally prefixed with `?`.
     *
     * @since 0.5.0
     */
    public static function valueType(): string;
}

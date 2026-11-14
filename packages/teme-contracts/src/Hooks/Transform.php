<?php

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * A hook that lets listeners change a value, one after another. A listener
 * is `fn (mixed $value, TheHook $hook): mixed` and returns the next value;
 * a value of the wrong type, or a listener that fails, keeps the value the
 * one before gave.
 *
 * @since 0.5.0
 */
interface Transform extends Hook
{
    /**
     * The PHP type every value must have: string, int, float, bool, array,
     * list, or a class or interface, optionally prefixed with `?`.
     *
     * @since 0.5.0
     */
    public static function valueType(): string;

    /**
     * The value before any listener changed it.
     *
     * @since 0.5.0
     */
    public function value(): mixed;
}

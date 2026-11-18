<?php

/**
 * Whether a value a listener gave has the type its hook names: string, int,
 * float, bool, array, list, or a class or interface, optionally prefixed
 * with `?` (I-3353).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Hooks;

final class TypeCheck
{
    /**
     * Returns true when $value has the type $type names.
     */
    public static function matches(string $type, mixed $value): bool
    {
        if (str_starts_with($type, '?')) {
            if ($value === null) {
                return true;
            }

            $type = substr($type, 1);
        }

        return match ($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'list' => is_array($value) && array_is_list($value),
            default => $value instanceof $type,
        };
    }

    /**
     * Returns the name of $value's type, for a fault that says what a
     * listener gave.
     */
    public static function of(mixed $value): string
    {
        return get_debug_type($value);
    }
}

<?php

/**
 * Reads the few WordPress meta values an export holds as serialized PHP:
 * attachment metadata and backup sizes, Rank Math's robots and a menu item's
 * classes. Each is unserialized without classes and to a bounded depth, and
 * one that holds an object is dropped; nothing serialized is ever kept.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

final class SerializedMeta
{
    /** The meta keys whose values WordPress serializes and the import reads. */
    public const array KEYS = ['_wp_attachment_metadata', '_wp_attachment_backup_sizes', 'rank_math_robots', '_menu_item_classes'];

    private const int MAX_DEPTH = 32;

    /**
     * Returns the value as an array: unserialized for one of the keys above,
     * null when it isn't serialized, is damaged, isn't an array or holds an
     * object; with whether it was dropped for holding an object.
     *
     * @return array{value: array<mixed>|null, dropped: bool}
     */
    public static function read(string $key, string $value): array
    {
        if (! in_array($key, self::KEYS, true)) {
            return ['value' => null, 'dropped' => false];
        }

        $trimmed = trim($value);

        if (preg_match('/^a:\d+:\{/', $trimmed) !== 1) {
            return ['value' => null, 'dropped' => preg_match('/^[OC]:\d+:"/', $trimmed) === 1];
        }

        $read = @unserialize($trimmed, ['allowed_classes' => false, 'max_depth' => self::MAX_DEPTH]);

        if (! is_array($read)) {
            return ['value' => null, 'dropped' => false];
        }

        if (self::holdsObject($read)) {
            return ['value' => null, 'dropped' => true];
        }

        return ['value' => $read, 'dropped' => false];
    }

    /**
     * Whether an object is anywhere in the array: with no class allowed, each arrives as an incomplete one.
     *
     * @param  array<mixed>  $value
     */
    private static function holdsObject(array $value): bool
    {
        foreach ($value as $inner) {
            if (is_object($inner)) {
                return true;
            }

            if (is_array($inner) && self::holdsObject($inner)) {
                return true;
            }
        }

        return false;
    }
}

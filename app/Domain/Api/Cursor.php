<?php

/**
 * The position a list was read to, for its next page (D5): the sort, the
 * sort key and the ID of the last item given, as base64url JSON. Opaque to
 * clients; never trusted beyond its shape.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api;

final readonly class Cursor
{
    private function __construct(public string $sort, public string $key, public string $id) {}

    /**
     * The cursor naming this position, for links.next.
     */
    public static function after(string $sort, string $key, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode([$sort, $key, $id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /**
     * The position this cursor names, for a list sorted by $sort, or null for
     * anything a list of that sort couldn't have given.
     */
    public static function read(string $cursor, string $sort): ?self
    {
        if (strlen($cursor) > 1024 || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        // Objects stay objects, so {"0": …, "1": …, "2": …} is refused, never read as a list.
        $parts = $json === false ? null : json_decode($json);

        if (! is_array($parts) || ! array_is_list($parts) || count($parts) !== 3 || $parts[0] !== $sort
            || ! is_string($parts[1]) || ! is_string($parts[2]) || preg_match(Ulids::LOWER, $parts[2]) !== 1) {
            return null;
        }

        return new self($parts[0], $parts[1], $parts[2]);
    }
}

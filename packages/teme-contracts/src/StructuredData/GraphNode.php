<?php

/**
 * One node a plugin gives a page's JSON-LD graph: schema.org's names for keys,
 * an absolute @id that other nodes link to, an @type, and plain values.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\StructuredData;

use InvalidArgumentException;

/**
 * One node a plugin gives a page's JSON-LD graph: schema.org's names for
 * keys, an absolute @id that other nodes link to, an @type, and plain values.
 * Checked when it is made, so the page's encoder can never fail on it.
 *
 * @since 1.1.0
 */
final readonly class GraphNode
{
    /**
     * @param  array<string, mixed>  $properties  an @id, an absolute address on the site; an @type, a name or a list of names; and values that are text in UTF-8, finite numbers, true, false, null or arrays of them; never an @context, which the graph carries once
     *
     * @throws InvalidArgumentException for a node that breaks those rules
     *
     * @since 1.1.0
     */
    public function __construct(public array $properties)
    {
        $id = $properties['@id'] ?? null;

        if (! is_string($id) || preg_match('~^https?://\S+$~', $id) !== 1) {
            throw new InvalidArgumentException('A graph node needs an @id, an absolute address.');
        }

        $type = $properties['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];

        if ($types === [] || ! array_is_list($types) || array_filter($types, fn (mixed $name): bool => ! is_string($name) || $name === '') !== []) {
            throw new InvalidArgumentException('A graph node needs an @type: a name, or a list of names.');
        }

        if (array_key_exists('@context', $properties)) {
            throw new InvalidArgumentException('A graph node can’t carry an @context; the graph has one.');
        }

        self::check($properties);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function check(array $values): void
    {
        foreach ($values as $value) {
            $plain = match (true) {
                is_array($value) => true,
                is_string($value) => mb_check_encoding($value, 'UTF-8'),
                is_float($value) => is_finite($value),
                default => is_int($value) || is_bool($value) || $value === null,
            };

            if (! $plain) {
                throw new InvalidArgumentException('A graph node holds only text in UTF-8, finite numbers, true, false, null and arrays of them.');
            }

            if (is_array($value)) {
                self::check($value);
            }
        }
    }
}

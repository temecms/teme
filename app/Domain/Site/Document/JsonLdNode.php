<?php

/**
 * One node of the page's JSON-LD graph (I-697): plain values keyed as
 * schema.org names them, with an absolute @id that other nodes link to and
 * an @type. Checked when it is made, so the encoder can never fail on it:
 * only text in UTF-8, finite numbers, booleans, nulls and arrays of them,
 * and no @context, which the graph carries once.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Document;

use InvalidArgumentException;

final readonly class JsonLdNode
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(public array $properties)
    {
        $id = $properties['@id'] ?? null;

        if (! is_string($id) || preg_match('~^https?://\S+$~', $id) !== 1) {
            throw new InvalidArgumentException('A JSON-LD node needs an @id, an absolute address.');
        }

        if (! self::isType($properties['@type'] ?? null)) {
            throw new InvalidArgumentException("JSON-LD node [{$id}] needs an @type: a name, or a list of names.");
        }

        if (array_key_exists('@context', $properties)) {
            throw new InvalidArgumentException("JSON-LD node [{$id}] carries an @context; the graph has one.");
        }

        self::check($properties, $id);
    }

    public function id(): string
    {
        return (string) $this->properties['@id'];
    }

    private static function isType(mixed $type): bool
    {
        if (is_string($type)) {
            return $type !== '';
        }

        if (! is_array($type) || $type === [] || ! array_is_list($type)) {
            return false;
        }

        foreach ($type as $name) {
            if (! is_string($name) || $name === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function check(array $values, string $id): void
    {
        foreach ($values as $value) {
            $plain = match (true) {
                is_array($value) => true,
                is_string($value) => mb_check_encoding($value, 'UTF-8'),
                is_float($value) => is_finite($value),
                default => is_int($value) || is_bool($value) || $value === null,
            };

            if (! $plain) {
                throw new InvalidArgumentException("JSON-LD node [{$id}] holds a value JSON can't carry as given: only text in UTF-8, finite numbers, true, false, null and arrays of them.");
            }

            if (is_array($value)) {
                self::check($value, $id);
            }
        }
    }
}

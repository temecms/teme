<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Lists;

use stdClass;

/**
 * Checks a decoded JSON value against the few JSON Schema keywords
 * teme-plugin.schema.json uses for `contributes` (type, required,
 * properties, additionalProperties, items, enum, pattern, minLength), so a
 * test can hold the schema's verdict beside the validator's without a
 * schema library.
 */
final class SchemaCheck
{
    /** Returns whether $value passes $schema, both decoded as objects. */
    public static function passes(mixed $value, stdClass $schema): bool
    {
        $rules = get_object_vars($schema);

        if (isset($rules['enum']) && ! in_array($value, $rules['enum'], true)) {
            return false;
        }

        $type = $rules['type'] ?? null;

        if ($type !== null && ! self::isType($value, $type)) {
            return false;
        }

        if (is_string($value)) {
            if (isset($rules['minLength']) && mb_strlen($value) < $rules['minLength']) {
                return false;
            }

            if (isset($rules['pattern']) && preg_match('/'.str_replace('/', '\/', $rules['pattern']).'/Du', $value) !== 1) {
                return false;
            }
        }

        if (is_array($value) && isset($rules['items'])) {
            foreach ($value as $item) {
                if (! self::passes($item, $rules['items'])) {
                    return false;
                }
            }
        }

        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            $properties = isset($rules['properties']) ? get_object_vars($rules['properties']) : [];

            foreach ($rules['required'] ?? [] as $name) {
                if (! array_key_exists($name, $fields)) {
                    return false;
                }
            }

            foreach ($fields as $name => $field) {
                if (isset($properties[$name])) {
                    if (! self::passes($field, $properties[$name])) {
                        return false;
                    }
                } elseif (($rules['additionalProperties'] ?? true) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => $value instanceof stdClass,
            'array' => is_array($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            default => true,
        };
    }
}

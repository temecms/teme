<?php

declare(strict_types=1);

namespace App\Domain\Support\Queries;

/**
 * Keyset paging positions (I-140): the sort values of the last row shown,
 * encoded so a URL can carry them. A cursor that does not decode to the
 * expected number of values is ignored, so a tampered URL shows page one.
 */
final class Cursor
{
    /**
     * The URL-safe cursor for a page that ends at a row with these sort values.
     *
     * @param  list<scalar|null>  $values
     */
    public static function encode(array $values): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($values)), '+/', '-_'), '=');
    }

    /**
     * The values in a cursor, or null if there is none or it is malformed.
     *
     * @return list<scalar|null>|null
     */
    public static function decode(?string $cursor, int $expected): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $values = $json === false ? null : json_decode($json, true);

        if (! is_array($values) || ! array_is_list($values) || count($values) !== $expected) {
            return null;
        }

        foreach ($values as $value) {
            if (! is_scalar($value) && $value !== null) {
                return null;
            }
        }

        /** @var list<scalar|null> $values */
        return $values;
    }
}

<?php

/**
 * How the export writes JSON (I-365): JSONB read back as objects, so an
 * empty object stays an object; one line per value; zero fractions,
 * slashes and every script kept, and the line and paragraph separators
 * escaped; times in UTC. A value that can't be written fails the export.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

final class ExportJson
{
    public const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** Deeper than any document the editor makes, and than PostgreSQL's own nesting. */
    public const int DEPTH = 1024;

    /**
     * A JSONB value as PostgreSQL returns it, as objects, never arrays: {}
     * and [] stay apart. Null stays null.
     */
    public static function decode(?string $json): mixed
    {
        return $json === null ? null : json_decode($json, false, self::DEPTH, JSON_THROW_ON_ERROR);
    }

    /**
     * One NDJSON line: the value, then a newline. Without
     * JSON_UNESCAPED_LINE_TERMINATORS, PHP escapes U+2028 and U+2029 even
     * with JSON_UNESCAPED_UNICODE.
     *
     * @param  array<string, mixed>  $value
     */
    public static function line(array $value): string
    {
        return json_encode($value, self::FLAGS, self::DEPTH)."\n";
    }

    /**
     * A time as the database gives it, in UTC with microseconds; a value
     * with no offset (a `timestamp` column) is UTC, as Laravel writes them.
     */
    public static function time(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /** Now, in the same form. */
    public static function now(): string
    {
        return Date::now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}

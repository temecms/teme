<?php

/**
 * Which types each public listing holds (I-3298): a term's archive and
 * feed every public type that uses its taxonomy; an author's and a date's
 * the public types with an archive; the home page, the posts page and the
 * main feed the types Settings › Reading ticks among those. Asked of the
 * registry each time, so a type made or changed a moment ago counts.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses;

use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Closure;
use Teme\Contracts\Settings\SettingsReader;

final class ListingTypes
{
    /** The Reading setting naming the home page's and the main feed's types. */
    public const string SETTING = 'home_types';

    /**
     * The public types with an archive, in the registry's order, code's
     * before stored.
     *
     * @return list<string>
     */
    public static function withArchive(): array
    {
        return self::keys(fn (ContentType $type): bool => $type->public && $type->archive);
    }

    /**
     * The public types that use this taxonomy, with an archive or not, in
     * the registry's order.
     *
     * @return list<string>
     */
    public static function usingTaxonomy(string $taxonomy): array
    {
        return self::keys(fn (ContentType $type): bool => $type->public && in_array($taxonomy, $type->taxonomies, true));
    }

    /**
     * The types the latest list and the main feed hold: those Reading ticks
     * that are still public with an archive, in the registry's order.
     *
     * @return list<string>
     */
    public static function onHome(): array
    {
        $ticked = app(SettingsReader::class)->stringList('reading', self::SETTING);

        return array_values(array_filter(self::withArchive(), fn (string $key): bool => in_array($key, $ticked, true)));
    }

    /**
     * The first public type with an archive, posts on every site so far:
     * terms', authors', dates' and listings' addresses take its pattern's
     * trailing slash, and Settings › Addresses offers its address the
     * presets. Null when no public type has an archive.
     */
    public static function first(): ?string
    {
        return self::withArchive()[0] ?? null;
    }

    /**
     * @param  Closure(ContentType): bool  $keep
     *
     * @return list<string>
     */
    private static function keys(Closure $keep): array
    {
        return array_values(array_map(fn (ContentType $type): string => $type->key, array_filter(app(ContentTypeRegistry::class)->all(), $keep)));
    }
}

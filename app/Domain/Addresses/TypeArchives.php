<?php

declare(strict_types=1);

namespace App\Domain\Addresses;

use App\Domain\Types\ContentType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Each content type's own archive page (I-3304): which types have one,
 * every public type with an archive but the first, whose list is the
 * latest list; the one word each sits at, from Settings › Addresses; the
 * type whose page is at a path; and what holds an address in its place.
 * Asked of the registry and the settings each time, so a type made or
 * changed a moment ago counts.
 */
final class TypeArchives
{
    /** The path table's target type for an archive page's old addresses; its target ID is the type's key. */
    public const string TARGET = 'type';

    /**
     * The types with an archive page, in the registry's order.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_slice(ListingTypes::withArchive(), 1);
    }

    /**
     * Where a type's archive page starts: the first fixed word of its own
     * address pattern, else its admin segment.
     */
    public static function defaultAddress(ContentType $type): string
    {
        try {
            $first = AddressPattern::parse($type->pattern ?? '/'.$type->segment.'/{slug}/')->firstFixedSegment();
        } catch (InvalidArgumentException) {
            $first = '';
        }

        return $first !== '' ? $first : $type->segment;
    }

    /**
     * The stored path of the type's archive page, or null when it has none.
     */
    public static function path(string $type): ?string
    {
        return AddressSettings::current()->archives[$type] ?? null;
    }

    /**
     * The type whose archive page is at this stored path, the first in
     * types() when two are, or null.
     */
    public static function at(string $path): ?string
    {
        $type = array_search($path, AddressSettings::current()->archives, true);

        return $type === false ? null : (string) $type;
    }

    /**
     * What lives at the type's archive page's address instead of it, in
     * words such as the page "Reels": a live address, else the archive page
     * of a type before it at the same address, as the archive page "Photos";
     * null when nothing does, or the type has no archive page.
     */
    public static function holder(string $type): ?string
    {
        $path = self::path($type);

        if ($path === null) {
            return null;
        }

        $row = DB::table('paths')->where('path', $path)->where('is_current', true)->first(['target_type', 'target_id']);

        if ($row !== null) {
            return Paths::describe((string) $row->target_type, (string) $row->target_id);
        }

        $first = self::at($path);

        return $first === null || $first === $type ? null : Paths::describe(self::TARGET, $first);
    }

    /**
     * The archive pages readers are shown, type key => stored path: those
     * whose address no live address holds, and no type before it in
     * types() has.
     *
     * @return array<string, string>
     */
    public static function shown(): array
    {
        $archives = AddressSettings::current()->archives;

        if ($archives === []) {
            return [];
        }

        $held = DB::table('paths')->whereIn('path', array_values($archives))->where('is_current', true)->pluck('path')->map(fn (mixed $path): string => (string) $path)->all();

        return array_filter($archives, fn (string $path, string $type): bool => ! in_array($path, $held, true) && array_search($path, $archives, true) === $type, ARRAY_FILTER_USE_BOTH);
    }
}

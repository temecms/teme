<?php

/**
 * Why a language may not take an address prefix (I-3360): the first thing
 * that already holds it, in words that name it. The site's own words,
 * another language, a type's archive page, a taxonomy's or the author
 * base, a type's pattern, or a live address at the prefix or under it. An
 * old address or a redirect never holds one: the language's home answers
 * first, and the banner warns of a redirect.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PrefixConflicts
{
    /**
     * The refusal for this prefix, or null when it is free; $exceptTag is
     * the language changing its own prefix, whose prefix and addresses
     * don't count.
     */
    public static function for(string $prefix, ?string $exceptTag = null): ?string
    {
        $shown = '/'.$prefix.'/';

        // The router reads a year's archive, page/N and comments/page/N before any prefix (A4).
        if (ctype_digit($prefix) || in_array($prefix, ['page', 'comments'], true)) {
            return __('Use a prefix with a letter in it, not page or comments.');
        }

        if (Paths::reserved($prefix)) {
            return __('The address :address is used by the site itself.', ['address' => $shown]);
        }

        $other = app(SiteLanguages::class)->byPrefix($prefix);

        if ($other !== null && $other !== $exceptTag) {
            return __('Another language already uses :address.', ['address' => $shown]);
        }

        $settings = AddressSettings::current();

        foreach ($settings->archives as $type => $path) {
            if ($path === $prefix || str_starts_with((string) $path, $prefix.'/')) {
                return __('The address :address is used by :holder.', ['address' => $shown, 'holder' => Paths::describe(TypeArchives::TARGET, (string) $type)]);
            }
        }

        $taxonomies = app(TaxonomyRegistry::class);

        foreach ($settings->bases as $taxonomy => $base) {
            if (explode('/', (string) $base)[0] === $prefix) {
                $name = $taxonomies->has((string) $taxonomy) ? mb_strtolower($taxonomies->get((string) $taxonomy)->singular) : (string) $taxonomy;

                return __('The :taxonomy base is :prefix: change it in Settings › Addresses first.', ['taxonomy' => $name, 'prefix' => $prefix]);
            }
        }

        if (explode('/', $settings->authorBase)[0] === $prefix) {
            return __('The author base is :prefix: change it in Settings › Addresses first.', ['prefix' => $prefix]);
        }

        $types = app(ContentTypeRegistry::class);

        foreach (array_keys($settings->patterns) as $type) {
            if (self::firstSegment($settings, (string) $type) === $prefix) {
                $plural = $types->has((string) $type) ? $types->get((string) $type)->plural : (string) $type;

                return __(':types addresses start with :address: change them in Settings › Addresses first.', ['types' => $plural, 'address' => $shown]);
            }
        }

        // Last: a setting that makes addresses there is named first, with where to change it.
        $holder = self::liveAddress($prefix, $exceptTag);

        if ($holder !== null) {
            return __('The address :address is used by :holder.', ['address' => $shown, 'holder' => Paths::describe((string) $holder->target_type, (string) $holder->target_id)]);
        }

        return null;
    }

    /**
     * The first live address at the prefix or under it, in one statement on
     * the path index, leaving out the changing language's own items and
     * terms.
     */
    private static function liveAddress(string $prefix, ?string $exceptTag): ?object
    {
        return DB::table('paths')
            ->where('is_current', true)
            ->where(fn ($query) => $query->where('path', $prefix)->orWhere('path', 'like', $prefix.'/%'))
            // The kinds of Paths::KINDS in a language; anything else under the prefix is a conflict.
            ->when($exceptTag !== null, fn ($query) => $query
                ->whereNotExists(fn ($own) => $own->from('contents')->whereColumn(DB::raw('contents.id::text'), 'paths.target_id')->where('paths.target_type', 'content')->where('contents.language', $exceptTag))
                ->whereNotExists(fn ($own) => $own->from('terms')->whereColumn(DB::raw('terms.id::text'), 'paths.target_id')->where('paths.target_type', 'term')->where('terms.language', $exceptTag)))
            ->orderBy('path')
            ->first(['target_type', 'target_id']);
    }

    private static function firstSegment(AddressSettings $settings, string $type): string
    {
        try {
            return $settings->pattern($type)->firstFixedSegment();
        } catch (InvalidArgumentException) {
            return '';
        }
    }
}

<?php

/**
 * Slugs for content: made from a title, unique among live items of one type
 * in one language (I-3360). Trashed items do not hold their slug (I-142).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Slugs
{
    /**
     * The slug a title would have before any de-duplication.
     */
    public static function fromTitle(string $title): string
    {
        $base = Str::slug($title) ?: 'untitled';

        // Leave room for a suffix inside the 255-character column. Titles may
        // be up to 512 characters, so without this a long title fails the
        // insert. WordPress and Craft both truncate at this point.
        return rtrim(mb_substr($base, 0, 200), '-');
    }

    /**
     * $base if no live item of this type in this language uses it,
     * otherwise $base with the next free numeric suffix. A null language is
     * the default.
     */
    public static function unique(string $type, string $base, ?string $language = null): string
    {
        // One query however many posts already share this title. Checking
        // "-2", "-3", "-4" in turn costs a query per collision, which does
        // not survive a million rows. The LIKE prefix is answered from the
        // live (type, slug) index because slug has the C collation.
        //
        // The plain slug is used whenever it is free. Only once it is taken
        // do we look for the highest numeric suffix — otherwise an existing
        // "covid-19" (from a title "Covid 19") would push a new "Covid" to
        // "covid-20". When both are taken the result can skip numbers, which
        // is harmless: it is still unique.
        $row = DB::table('contents')
            ->where('type', $type)
            ->whereNull('deleted_at')
            // One language's slugs: null is the default language (I-3360).
            ->where(fn ($query) => $language === null ? $query->whereNull('language') : $query->where('language', $language))
            ->where(fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
            ->whereRaw('slug ~ ?', ['^'.preg_quote($base, '/').'(-[0-9]+)?$'])
            ->selectRaw(
                'max(case when slug = ? then 1 else 0 end) as base_taken, '
                // The position argument must be cast explicitly: an untyped bound
                // parameter here makes Postgres resolve substring(text, text) —
                // the regex-extraction overload — instead of substring(text, int),
                // and it silently returns null rather than erroring.
                .'max(case when slug = ? then 1 else substring(slug from ?::int)::int end) as highest',
                [$base, $base, mb_strlen($base) + 2],
            )
            ->first();

        if ((int) ($row->base_taken ?? 0) === 0) {
            return $base;
        }

        return $base.'-'.((int) $row->highest + 1);
    }

    /**
     * Whether no other live item of this type in this language uses the
     * slug. A null language is the default.
     */
    public static function isAvailable(string $type, string $slug, ?string $exceptId = null, ?string $language = null): bool
    {
        return ! DB::table('contents')
            ->where('type', $type)
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $language === null ? $query->whereNull('language') : $query->where('language', $language))
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }
}

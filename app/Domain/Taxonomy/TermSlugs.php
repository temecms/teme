<?php

/**
 * Slugs for categories and tags, unique within their taxonomy in one
 * language (I-3360): the same single-query approach as content slugs.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy;

use Illuminate\Support\Facades\DB;

final class TermSlugs
{
    /**
     * $base if free in this taxonomy, otherwise $base with the next free
     * numeric suffix. $exceptId leaves one term out, for renames; a null
     * language is the default.
     */
    public static function unique(string $taxonomy, string $base, ?string $exceptId = null, ?string $language = null): string
    {
        $row = DB::table('terms')
            ->where('taxonomy', $taxonomy)
            ->where(fn ($query) => $language === null ? $query->whereNull('language') : $query->where('language', $language))
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where(fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
            ->whereRaw('slug ~ ?', ['^'.preg_quote($base, '/').'(-[0-9]+)?$'])
            ->selectRaw(
                'max(case when slug = ? then 1 else 0 end) as base_taken, '
                .'max(case when slug = ? then 1 else substring(slug from ?::int)::int end) as highest',
                [$base, $base, mb_strlen($base) + 2],
            )
            ->first();

        if ((int) ($row->base_taken ?? 0) === 0) {
            return $base;
        }

        return $base.'-'.((int) $row->highest + 1);
    }
}

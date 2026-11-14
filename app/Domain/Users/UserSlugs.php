<?php

declare(strict_types=1);

namespace App\Domain\Users;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slugs for people, for their author address: made from their name, unique
 * among every account and every person with no account (I-3366).
 */
final class UserSlugs
{
    /**
     * The name's slug, or `author` for a name with no letters or numbers,
     * with the next free suffix (`-2`, `-3` …) when an account or a person
     * has it.
     */
    public static function unique(string $name): string
    {
        return self::free(Str::slug($name) ?: 'author');
    }

    /**
     * This slug, or it with the next free suffix (`-2`, `-3` …) when an
     * account or a person with no account has it. One query however many
     * share it, as Slugs::unique does for content.
     */
    public static function free(string $base): string
    {
        $pattern = '^'.preg_quote($base, '/').'(-[0-9]+)?$';
        $taken = DB::table('users')->select('slug')
            ->where(fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
            ->whereRaw('slug ~ ?', [$pattern])
            ->unionAll(DB::table('people')->select('slug')
                ->where(fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
                ->whereRaw('slug ~ ?', [$pattern]));

        $row = DB::query()->fromSub($taken, 'taken')
            ->selectRaw(
                'max(case when slug = ? then 1 else 0 end) as base_taken, '
                // Cast the position: an untyped parameter makes Postgres pick
                // substring(text, text), which silently returns null (Slugs).
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

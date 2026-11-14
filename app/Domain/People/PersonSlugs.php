<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Users\UserSlugs;
use Illuminate\Support\Str;

/**
 * Slugs for people with no account, for their author address: made from
 * their name, unique among every account and every person (I-3366).
 */
final class PersonSlugs
{
    /**
     * The name's slug, or `person` for a name with no letters or numbers,
     * with the next free suffix (`-2`, `-3` …) when an account or a person
     * has it.
     */
    public static function unique(string $name): string
    {
        return UserSlugs::free(Str::slug($name) ?: 'person');
    }

    /**
     * Whether no account and no person has this slug.
     */
    public static function isFree(string $slug): bool
    {
        return UserSlugs::free($slug) === $slug;
    }
}

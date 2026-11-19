<?php

/**
 * The author archives as a child of their own (I-296): the people credited
 * on live items of the types with an archive, each at their archive's
 * address; accounts in ID order, then people with no account in theirs
 * (I-3366). None while Search and social hides author archives.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Sitemaps;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cache\PageTags;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Sitemaps\Queries\ListedItems;
use Illuminate\Support\Facades\DB;

final readonly class AuthorsSitemap implements SitemapProvider
{
    public const string NAME = 'authors';

    /**
     * The people listed, as their archives are: at a current address, and
     * credited on a live item of a type their archives list, as
     * ListingViews::forAuthor() lists them. Each row has its order (`rank`
     * 0 for an account, 1 for a person with no account, then `num` and
     * `ulid`), its `id` and its `path`.
     *
     * @param  list<string>  $types
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function listed(array $types): array
    {
        $in = implode(', ', array_fill(0, count($types), '?'));
        // ListingViews::archive()'s predicate on an item c.
        $live = "c.type IN ({$in}) AND c.status = 'published' AND c.deleted_at IS NULL AND c.published_at <= ? AND c.visibility <> 'private'";
        $now = now();

        $sql = <<<SQL
            SELECT 0 AS rank, person.id AS num, '' AS ulid, person.id::text AS id, address.path
            FROM users person
            JOIN paths address ON address.target_type = 'user' AND address.target_id = person.id::text AND address.is_current
            WHERE EXISTS (
                SELECT 1 FROM contents c
                WHERE c.author_id = person.id AND {$live}
                  AND NOT EXISTS (SELECT 1 FROM content_bylines o WHERE o.content_id = c.id)
            ) OR EXISTS (
                SELECT 1 FROM people p
                JOIN content_bylines b ON b.person_id = p.id
                JOIN contents c ON c.id = b.content_id
                WHERE p.user_id = person.id AND {$live}
            )
            UNION ALL
            SELECT 1, 0, guest.id::text, guest.id::text, address.path
            FROM people guest
            JOIN paths address ON address.target_type = 'person' AND address.target_id = guest.id::text AND address.is_current
            WHERE guest.user_id IS NULL AND EXISTS (
                SELECT 1 FROM content_bylines b
                JOIN contents c ON c.id = b.content_id
                WHERE b.person_id = guest.id AND {$live}
            )
            SQL;

        return [$sql, [...$types, $now, ...$types, $now, ...$types, $now]];
    }

    public function names(): array
    {
        $types = ListingTypes::withArchive();

        if ($types === [] || TitlesAndListing::hidesAuthors()) {
            return [];
        }

        [$listed, $bindings] = self::listed($types);

        return DB::selectOne("SELECT EXISTS (SELECT 1 FROM ({$listed}) listed) AS found", $bindings)?->found === true ? [self::NAME] : [];
    }

    /**
     * Each page's first key: an account's ID, or a person with no account's
     * ULID, 26 characters, which no account's ID is as long as.
     */
    public function starts(string $name): array
    {
        $types = ListingTypes::withArchive();

        if ($types === []) {
            return [];
        }

        [$listed, $bindings] = self::listed($types);
        $sql = "SELECT id FROM (SELECT listed.id, listed.rank, listed.num, listed.ulid, row_number() OVER (ORDER BY listed.rank, listed.num, listed.ulid) AS n FROM ({$listed}) listed) counted WHERE n > 1 AND n % ? = 1 ORDER BY rank, num, ulid";

        return array_map(fn (object $row): string => (string) $row->id, DB::select($sql, [...$bindings, ListedItems::PAGE]));
    }

    public function page(string $name, ?string $from): SitemapPage
    {
        $types = ListingTypes::withArchive();

        if ($types === []) {
            return new SitemapPage([]);
        }

        [$listed, $bindings] = self::listed($types);
        // A person with no account's key is a ULID; any other is an account's ID.
        $guest = $from !== null && strlen($from) === 26;
        $rows = DB::select(
            "SELECT listed.id, listed.path FROM ({$listed}) listed WHERE (listed.rank, listed.num, listed.ulid) >= (?, ?, ?) ORDER BY listed.rank, listed.num, listed.ulid LIMIT ?",
            [...$bindings, $guest ? 1 : 0, $guest ? 0 : (int) ($from ?? 0), $guest ? (string) $from : '', ListedItems::PAGE],
        );
        $root = rtrim(url('/'), '/');

        return new SitemapPage(
            array_map(fn (object $row): SitemapAddress => new SitemapAddress($root.PublicRequestPath::encode(Addresses::toUrl((string) $row->path))), $rows),
            array_map(fn (object $row): string => PageTags::person((string) $row->id), $rows),
        );
    }

    public function tags(string $name): array
    {
        return array_map(PageTags::listOf(...), ListingTypes::withArchive());
    }
}

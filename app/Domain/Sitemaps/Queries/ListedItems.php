<?php

/**
 * Which items search engines may list, as each page's head decides it:
 * live, at a current address, not hidden by its own box, its canonical
 * pointing nowhere else, and the front page and the posts page as the head
 * treats them. Read a page at a time from a key, never by OFFSET; llms.txt
 * is to read the same rule (I-713). A type's own hide box is its caller's
 * to ask. A sitemap's pages list every shown language's items (I-3360);
 * the newest, the latest and whether a type has any keep to one language,
 * the default unless one is named; one item is read in any shown language.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Sitemaps\Queries;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Languages\SiteLanguages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ListedItems
{
    /** At most this many live items a page (I-296). */
    public const int PAGE = 2000;

    /** CanonicalChoice::ADDRESS in PostgreSQL's words, matched in any case: an address on another site. */
    public const string ADDRESS = '^https?://[^\s"\'<>`]+$';

    /**
     * Whether the item on the alias `live` is listed by its own box and its
     * canonical, as PageHead resolves them: `true` in its box hides it; a
     * canonical naming another item that has a current address, or a text
     * or an `address` that is another site's, sends it elsewhere; the posts
     * page, the first binding, always prints its own address. The second
     * binding is ADDRESS.
     */
    private const string LISTED = <<<'SQL'
        live.fields -> '{noindex}' IS DISTINCT FROM 'true'::jsonb
        AND (live.id = ? OR CASE
            WHEN jsonb_typeof(live.fields -> '{canonical}' -> 'item') = 'string'
                AND live.fields -> '{canonical}' ->> 'item' <> live.id::text
                THEN NOT EXISTS (
                    SELECT 1 FROM paths named
                    WHERE named.target_type = 'content'
                        AND named.target_id = live.fields -> '{canonical}' ->> 'item'
                        AND named.is_current
                )
            ELSE COALESCE(CASE jsonb_typeof(live.fields -> '{canonical}')
                WHEN 'string' THEN btrim(live.fields ->> '{canonical}')
                WHEN 'object' THEN live.fields -> '{canonical}' ->> 'address'
            END, '') !~* ?
        END)
        SQL;

    public function __construct(private readonly SiteLanguages $languages) {}

    /**
     * The first ID of each page after the first, for this type's live items
     * in every shown language in ID order, one every PAGE: one pass over
     * contents_sitemap.
     *
     * @return list<string>
     */
    public function starts(string $type): array
    {
        $sql = <<<'SQL'
            SELECT id FROM (
                SELECT id, row_number() OVER (ORDER BY id) AS n
                FROM contents
                WHERE type = ? AND status = 'published' AND deleted_at IS NULL AND visibility = 'public' AND published_at <= ?{default}
            ) live
            WHERE n > 1 AND n % ? = 1
            ORDER BY id
            SQL;

        [$shown, $bindings] = $this->inShown();

        return array_map(fn (object $row): string => (string) $row->id, DB::select(str_replace('{default}', $shown, $sql), [$type, now(), ...$bindings, self::PAGE]));
    }

    /**
     * One page of this type's listed items in every shown language from the
     * key $from, the first page when null: of the next PAGE live items in ID order, those a
     * page's head lets search engines list, each with its current address
     * and when it last changed, the later of going live and its published
     * revision. The front page is left out: it is the site's address.
     *
     * @return list<array{id: string, path: string, changed_at: string, group: string|null}>
     */
    public function page(string $type, ?string $from): array
    {
        [$front, $posts] = self::sitePages();
        $sql = <<<'SQL'
            SELECT live.id, address.path, GREATEST(live.published_at, revision.created_at) AS changed_at
            FROM (
                SELECT id, published_at, published_revision_id, fields
                FROM contents
                WHERE type = ? AND status = 'published' AND deleted_at IS NULL AND visibility = 'public' AND published_at <= ? AND id >= ?{default}
                ORDER BY id
                LIMIT ?
            ) live
            JOIN content_revisions revision ON revision.id = live.published_revision_id
            JOIN paths address ON address.target_type = 'content' AND address.target_id = live.id::text AND address.is_current
            WHERE live.id <> ?
            SQL;

        [$shown, $bindings] = $this->inShown();
        $sql = str_replace(['{default}', 'SELECT id, published_at'], [$shown, 'SELECT id, translation_group, published_at'], $sql);
        $sql = str_replace('SELECT live.id, address.path', 'SELECT live.id, live.translation_group, address.path', $sql);
        $rows = DB::select($sql.' AND '.self::rule().' ORDER BY live.id', [$type, now(), $from ?? '', ...$bindings, self::PAGE, $front, $posts, self::ADDRESS]);

        return array_map(fn (object $row): array => [
            'id' => (string) $row->id,
            'path' => (string) $row->path,
            'changed_at' => (string) $row->changed_at,
            'group' => $row->translation_group === null ? null : (string) $row->translation_group,
        ], $rows);
    }

    /**
     * At most $count of this type's listed items, newest first: of its
     * newest live, public items, those a page's head lets search engines
     * list (I-1118), each with its current address, published title and
     * excerpt. The front page is left out: it is the site's address. Fewer
     * when some of the newest are hidden.
     *
     * @return list<array{id: string, path: string, title: string, excerpt: string|null}>
     */
    public function latestListed(string $type, int $count, ?string $language = null): array
    {
        [$front, $posts] = self::sitePages();
        $sql = <<<'SQL'
            SELECT live.id, address.path, revision.title, revision.fields ->> ? AS excerpt
            FROM (
                SELECT id, published_at, published_revision_id, fields
                FROM contents
                WHERE type = ? AND status = 'published' AND deleted_at IS NULL AND visibility = 'public' AND published_at <= ?{default}
                ORDER BY published_at DESC, id DESC
                LIMIT ?
            ) live
            JOIN content_revisions revision ON revision.id = live.published_revision_id
            JOIN paths address ON address.target_type = 'content' AND address.target_id = live.id::text AND address.is_current
            WHERE live.id <> ?
            SQL;

        [$sql, $bindings] = $this->inLanguage($sql, $language);
        $rows = DB::select($sql.' AND '.self::rule().' ORDER BY live.published_at DESC, live.id DESC', [BuiltInFields::EXCERPT, $type, now(), ...$bindings, $count, $front, $posts, self::ADDRESS]);

        return array_map(fn (object $row): array => [
            'id' => (string) $row->id,
            'path' => (string) $row->path,
            'title' => (string) $row->title,
            'excerpt' => is_string($row->excerpt) && trim($row->excerpt) !== '' ? trim($row->excerpt) : null,
        ], $rows);
    }

    /**
     * Whether this type has a live item in this language, as stored: null
     * for the default.
     */
    public function any(string $type, ?string $language = null): bool
    {
        $sql = <<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM contents
                WHERE type = ? AND status = 'published' AND deleted_at IS NULL AND visibility = 'public' AND published_at <= ?{default}
            ) AS found
            SQL;

        [$sql, $bindings] = $this->inLanguage($sql, $language);

        return DB::selectOne($sql, [$type, now(), ...$bindings])?->found === true;
    }

    /**
     * Whether this type has a live item in any shown language.
     *
     * @since 1.0.0
     */
    public function anyShown(string $type): bool
    {
        [$shown, $bindings] = $this->inShown();
        $sql = <<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM contents
                WHERE type = ? AND status = 'published' AND deleted_at IS NULL AND visibility = 'public' AND published_at <= ?{default}
            ) AS found
            SQL;

        return DB::selectOne(str_replace('{default}', $shown, $sql), [$type, now(), ...$bindings])?->found === true;
    }

    /**
     * When this item last changed, if it is live and listed by its own box
     * and canonical, as the front page is, its type's hide box aside
     * (I-854), in a shown language; null otherwise.
     */
    public function listed(string $id): ?CarbonImmutable
    {
        $hidden = $this->languages->hidden();
        $sql = <<<'SQL'
            SELECT GREATEST(live.published_at, revision.created_at) AS changed_at
            FROM contents live
            JOIN content_revisions revision ON revision.id = live.published_revision_id
            WHERE live.id = ? AND live.status = 'published' AND live.deleted_at IS NULL AND live.visibility = 'public' AND live.published_at <= ?
            SQL;

        $shown = $hidden === [] ? '' : ' AND (live.language IS NULL OR live.language <> ALL(?::text[]))';
        $row = DB::selectOne($sql.$shown.' AND '.self::rule(), [$id, now(), ...($hidden === [] ? [] : ['{'.implode(',', $hidden).'}']), '', self::ADDRESS]);

        return $row === null ? null : CarbonImmutable::parse((string) $row->changed_at);
    }

    /**
     * Of these items, those live and listed by their own box and canonical
     * at a current address, in a shown language, as listed() decides one,
     * each with its address's path, type and when it last changed, by ID.
     * A plugin's sitemap names them (docs/guides/developers/plugins/seo.md).
     *
     * @param  list<string>  $ids
     *
     * @return array<string, array{path: string, type: string, changed_at: string}>
     *
     * @since 1.1.0
     */
    public function addressesOf(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id): bool => preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $id) === 1)));

        if ($ids === []) {
            return [];
        }

        $hidden = $this->languages->hidden();
        $sql = <<<'SQL'
            SELECT live.id, live.type, address.path, GREATEST(live.published_at, revision.created_at) AS changed_at
            FROM contents live
            JOIN content_revisions revision ON revision.id = live.published_revision_id
            JOIN paths address ON address.target_type = 'content' AND address.target_id = live.id::text AND address.is_current
            WHERE live.id = ANY(?::text[]) AND live.status = 'published' AND live.deleted_at IS NULL AND live.visibility = 'public' AND live.published_at <= ?
            SQL;

        $shown = $hidden === [] ? '' : ' AND (live.language IS NULL OR live.language <> ALL(?::text[]))';
        $rows = DB::select($sql.$shown.' AND '.self::rule(), ['{'.implode(',', $ids).'}', now(), ...($hidden === [] ? [] : ['{'.implode(',', $hidden).'}']), '', self::ADDRESS]);
        $found = [];

        foreach ($rows as $row) {
            $found[(string) $row->id] = ['path' => (string) $row->path, 'type' => (string) $row->type, 'changed_at' => (string) $row->changed_at];
        }

        return $found;
    }

    /**
     * When the newest live item of these types, in this language as stored
     * (null for the default), last changed, the later of going live and its
     * published revision: the date the site's address carries while it
     * lists them; null for no types, or none live.
     *
     * @param  list<string>  $types
     *
     * @since 1.0.0
     */
    public function newestIn(array $types, ?string $language): ?CarbonImmutable
    {
        if ($types === []) {
            return null;
        }

        $in = implode(', ', array_fill(0, count($types), '?'));
        $sql = <<<SQL
            SELECT GREATEST(live.published_at, revision.created_at) AS changed_at
            FROM (
                SELECT published_at, published_revision_id
                FROM contents
                WHERE type IN ({$in}) AND status = 'published' AND deleted_at IS NULL AND visibility = 'public' AND published_at <= ?{default}
                ORDER BY published_at DESC, id DESC
                LIMIT 1
            ) live
            JOIN content_revisions revision ON revision.id = live.published_revision_id
            SQL;

        [$sql, $bindings] = $this->inLanguage($sql, $language);
        $row = DB::selectOne($sql, [...$types, now(), ...$bindings]);

        return $row === null ? null : CarbonImmutable::parse((string) $row->changed_at);
    }

    /**
     * As newestIn(), in the default language.
     */
    public function newest(string ...$types): ?CarbonImmutable
    {
        return $this->newestIn(array_values($types), null);
    }

    /**
     * Every live, public version of these groups in a shown language that
     * search engines aren't told not to list, each with its current address,
     * by group, for a sitemap page's alternates: one statement.
     *
     * @param  list<string>  $groups
     *
     * @return array<string, list<array{id: string, language: string|null, type: string, path: string}>>
     *
     * @since 1.0.0
     */
    public function versions(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        [$shown, $bindings] = $this->inShown('c.language');
        $rows = DB::select(<<<SQL
            SELECT c.id, c.language, c.type, c.translation_group, p.path
            FROM contents c
            JOIN paths p ON p.target_type = 'content' AND p.target_id = c.id::text AND p.is_current
            WHERE c.translation_group = ANY(?::text[])
                AND c.status = 'published' AND c.deleted_at IS NULL AND c.visibility = 'public' AND c.published_at <= ?{$shown}
                AND (c.fields -> ?) IS DISTINCT FROM 'true'::jsonb
            ORDER BY c.id
            SQL, ['{'.implode(',', $groups).'}', now(), ...$bindings, BuiltInFields::NOINDEX]);

        $versions = [];

        foreach ($rows as $row) {
            $versions[(string) $row->translation_group][] = [
                'id' => (string) $row->id,
                'language' => $row->language === null ? null : (string) $row->language,
                'type' => (string) $row->type,
                'path' => (string) $row->path,
            ];
        }

        return $versions;
    }

    /**
     * The front page's and the posts page's IDs while the site's address
     * shows a page, '' for each otherwise.
     *
     * @return array{0: string, 1: string}
     */
    private static function sitePages(): array
    {
        $settings = AddressSettings::current();

        return $settings->frontShows === AddressSettings::FRONT_PAGE ? [$settings->frontPageId, $settings->postsPageId] : ['', ''];
    }

    /**
     * A statement with its `{default}` mark made one language's condition,
     * the default's (SiteLanguages::defaultOnlySql()) when none is named,
     * with its bindings.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function inLanguage(string $sql, ?string $language): array
    {
        return $language === null
            ? [str_replace('{default}', $this->languages->defaultOnlySql('language'), $sql), []]
            : [str_replace('{default}', ' AND language = ?', $sql), [$language]];
    }

    /**
     * The condition that keeps to the shown languages' rows, the default's
     * always among them, with its bindings: the shown other languages bound
     * as one array literal.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function inShown(string $column = 'language'): array
    {
        $others = array_values(array_filter(array_map(fn (string $tag): ?string => $this->languages->stored($tag), $this->languages->shown())));

        return $others === []
            ? [$this->languages->defaultOnlySql($column), []]
            : [" AND ({$column} IS NULL OR {$column} = ANY(?::text[]))", ['{'.implode(',', $others).'}']];
    }

    /**
     * LISTED with the two fields' keys written in: constants, never input.
     */
    private static function rule(): string
    {
        return strtr(self::LISTED, ['{noindex}' => BuiltInFields::NOINDEX, '{canonical}' => BuiltInFields::CANONICAL]);
    }
}

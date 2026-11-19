<?php

/**
 * Who each item credits (I-3366): its bylines in order, or, with none, its
 * owner. Every reader of the byline asks here, so the rule is one.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\People\Queries\People;
use Illuminate\Support\Facades\DB;

final class Credits
{
    /** The most people one item credits. */
    public const int MOST = 20;

    /**
     * Each item's credits, by content ID, in order; one statement however
     * many items, and none for an empty list. An item with no bylines
     * credits its owner's account, read from the owner given, as before.
     *
     * @param  array<string, int|null>  $owners  content ID => its author_id
     *
     * @return array<string, list<Credit>>
     */
    public function forItems(array $owners): array
    {
        $credits = [];

        foreach (array_keys($owners) as $id) {
            $credits[(string) $id] = [];
        }

        if ($credits === []) {
            return [];
        }

        foreach (DB::table('content_bylines as b')->join('people as p', 'p.id', '=', 'b.person_id')
            ->whereIn('b.content_id', array_map(strval(...), array_keys($credits)))
            ->orderBy('b.content_id')->orderBy('b.position')
            ->get(['b.content_id', 'b.person_id', 'p.user_id']) as $row) {
            $credits[(string) $row->content_id][] = $row->user_id === null
                ? Credit::person((string) $row->person_id)
                : Credit::account((int) $row->user_id);
        }

        foreach ($owners as $id => $owner) {
            if ($credits[(string) $id] === [] && $owner !== null) {
                $credits[(string) $id] = [Credit::account((int) $owner)];
            }
        }

        return $credits;
    }

    /**
     * Each item's credits as their author pages show them, by content ID, in
     * order, in one statement: People::shown()'s rows. An item with no
     * bylines credits its owner, read from the item's own row; one that
     * credits no one has an empty list. Each one shown is tagged
     * `person:{id}` on the page being drawn.
     *
     * @param  list<string>  $contentIds
     *
     * @return array<string, list<object{kind: string, id: string, name: string, slug: string, bio: string|null, path: string|null}>>
     */
    public function shown(array $contentIds): array
    {
        $contentIds = array_values(array_unique(array_map(strval(...), $contentIds)));
        $shown = [];

        foreach ($contentIds as $id) {
            $shown[$id] = [];
        }

        if ($contentIds === []) {
            return [];
        }

        $in = People::placeholders($contentIds);
        $rows = DB::select(<<<SQL
            SELECT f.content_id, f.kind, f.id, f.name, f.slug, f.bio, a.path
            FROM (
                SELECT b.content_id, b.position,
                       CASE WHEN p.user_id IS NULL THEN 'person' ELSE 'user' END AS kind,
                       COALESCE(p.user_id::text, p.id::text) AS id,
                       COALESCE(p.name, u.name) AS name, COALESCE(p.slug, u.slug) AS slug, COALESCE(p.bio, u.bio) AS bio
                FROM content_bylines b
                JOIN people p ON p.id = b.person_id
                LEFT JOIN users u ON u.id = p.user_id
                WHERE b.content_id IN ({$in})
                UNION ALL
                SELECT c.id, 0, 'user', u.id::text, u.name, u.slug, u.bio
                FROM contents c
                JOIN users u ON u.id = c.author_id
                WHERE c.id IN ({$in})
                  AND NOT EXISTS (SELECT 1 FROM content_bylines o WHERE o.content_id = c.id)
            ) f
            LEFT JOIN paths a ON a.target_type = f.kind AND a.target_id = f.id AND a.is_current
            ORDER BY f.content_id, f.position
            SQL, [...$contentIds, ...$contentIds]);

        $tags = [];

        foreach ($rows as $row) {
            $person = People::row($row);
            $shown[(string) $row->content_id][] = $person;
            $tags[$person->id] = PageTags::person($person->id);
        }

        if ($tags !== []) {
            app(RenderRecord::class)->tag(...array_values($tags));
        }

        return $shown;
    }

    /**
     * The title of the item's working revision, as the editor shows it; null
     * when there's no such item.
     */
    public function title(string $contentId): ?string
    {
        $title = DB::table('contents as c')->join('content_revisions as r', 'r.id', '=', 'c.working_revision_id')->where('c.id', $contentId)->value('r.title');

        return $title === null ? null : (string) $title;
    }

    /**
     * The IDs of the items that credit someone, as SQL for `contents.id IN (…)`.
     * A person with no account: the bylines that name them. An account: the
     * bylines that name its person, and the items it owns that have none.
     * Each branch has its own index (content_bylines_person_id_content_id_index,
     * people_user_id_unique, contents_author_id_index or contents_author_id_id), so a list never scans contents.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public function itemIdsSql(Credit $credit): array
    {
        if ($credit->kind === Credit::PERSON) {
            return ['SELECT b.content_id FROM content_bylines b WHERE b.person_id = ?', [$credit->id]];
        }

        return ['SELECT b.content_id FROM content_bylines b JOIN people p ON p.id = b.person_id WHERE p.user_id = ?'
            .' UNION ALL SELECT c.id FROM contents c WHERE c.author_id = ?'
            .' AND NOT EXISTS (SELECT 1 FROM content_bylines o WHERE o.content_id = c.id)', [(int) $credit->id, (int) $credit->id]];
    }

    /**
     * Whether anything credits the account: an item it owns that has no
     * bylines, in any state, or a byline that names its person. One rule for
     * deleting an account and for answering its privacy requests.
     */
    public function creditsAccount(int $userId): bool
    {
        return (bool) DB::scalar(<<<'SQL'
            SELECT EXISTS (SELECT 1 FROM contents c WHERE c.author_id = ? AND NOT EXISTS (SELECT 1 FROM content_bylines o WHERE o.content_id = c.id))
                OR EXISTS (SELECT 1 FROM content_bylines b JOIN people p ON p.id = b.person_id WHERE p.user_id = ?)
            SQL, [$userId, $userId]);
    }

    /**
     * How many items credit someone, in any state; one statement.
     */
    public function itemCount(Credit $credit): int
    {
        [$sql, $bindings] = $this->itemIdsSql($credit);

        return (int) DB::scalar('SELECT count(*) FROM ('.$sql.') credited', $bindings);
    }

    /**
     * Whether any byline touches an account: one names its person, or one
     * of the items it owns has bylines. While none does, its archive is read
     * as before bylines. One statement, each part answered by indexes alone:
     * the account's items come from contents_author_id_id, merged with the
     * bylines' own index, so no item's row is read (Follow-up L2).
     */
    public function bylinesTouch(int $userId): bool
    {
        return (bool) DB::scalar(<<<'SQL'
            SELECT EXISTS (SELECT 1 FROM content_bylines b JOIN people p ON p.id = b.person_id WHERE p.user_id = ?)
                OR EXISTS (SELECT 1 FROM contents c WHERE c.author_id = ? AND EXISTS (SELECT 1 FROM content_bylines o WHERE o.content_id = c.id))
            SQL, [$userId, $userId]);
    }

    /**
     * The address slug of the item's first credit as stored now, or null;
     * one statement, read only for a pattern that holds {author}.
     */
    public function firstSlug(string $contentId): ?string
    {
        $slug = DB::scalar(<<<'SQL'
            SELECT f.slug
            FROM (
                SELECT b.position, COALESCE(p.slug, u.slug) AS slug
                FROM content_bylines b
                JOIN people p ON p.id = b.person_id
                LEFT JOIN users u ON u.id = p.user_id
                WHERE b.content_id = ?
                UNION ALL
                SELECT 0, u.slug
                FROM contents c
                JOIN users u ON u.id = c.author_id
                WHERE c.id = ? AND NOT EXISTS (SELECT 1 FROM content_bylines x WHERE x.content_id = c.id)
            ) f
            ORDER BY f.position
            LIMIT 1
            SQL, [$contentId, $contentId]);

        return $slug === null ? null : (string) $slug;
    }
}

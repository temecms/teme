<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Visibility;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\People\Credits;
use App\Domain\Site\SiteDates;
use App\Domain\Taxonomy\Queries\TermTree;
use App\Domain\Types\CoreTypes;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Feeds\FeedsChanged;

/**
 * The page-cache tags of the pages that show one thing or list it, as the
 * thing stands (docs/architecture/page-cache.md): an Operation reads a
 * thing's under its lock before its change and again after, and both are
 * purged after the outermost commit (I-263). While the page cache is off
 * nothing is stored, so nothing is read and every footprint is empty,
 * unless a plugin listens for the feeds a change touched, which are read
 * from these tags (docs/plans/plugin-sockets-1.md, A4).
 */
final class Footprints
{
    /**
     * A content item's: its page, its address, and each list it is in, its
     * type's, its terms', each credited person's, its year's and its month's, while it
     * is published; a region's, every page that draws it; otherwise none.
     *
     * @return list<string>
     */
    public static function content(Content $content): array
    {
        return self::contents([$content])[(string) $content->id] ?? [];
    }

    /**
     * Each item's footprint, keyed by ID, read in three statements however
     * many there are.
     *
     * @param  iterable<Content>  $contents
     *
     * @return array<string, list<string>>
     */
    public static function contents(iterable $contents): array
    {
        $footprints = [];
        $published = [];

        foreach ($contents as $content) {
            $id = (string) $content->id;
            $footprints[$id] = [];

            if (! self::read()) {
                continue;
            }

            if ($content->type === CoreTypes::REGION) {
                $footprints[$id] = [PageTags::region((string) $content->slug)];
            } elseif (! $content->trashed() && $content->status === 'published' && $content->published_revision_id !== null && $content->visibility !== Visibility::Private) {
                // A private item shows readers nothing (I-1700).
                // Dated ahead too: its address and lists are purged a little
                // early, which costs a redraw, never a stale page.
                $published[$id] = $content;
            }
        }

        if ($published === []) {
            return $footprints;
        }

        $ids = array_map(strval(...), array_keys($published));
        $paths = DB::table('paths')->where('target_type', 'content')->whereIn('target_id', $ids)->where('is_current', true)->pluck('path', 'target_id');
        // Each credit's list holds it: its bylines', or its owner's with none (I-3366).
        $credits = app(Credits::class)->forItems(array_map(fn (Content $content): ?int => $content->author_id === null ? null : (int) $content->author_id, $published));

        foreach ($published as $id => $content) {
            $id = (string) $id;
            $tags = [PageTags::content($id), PageTags::listOf((string) $content->type)];

            // Its parent's page lists it (I-3363).
            if ($content->parent_id !== null) {
                $tags[] = PageTags::childrenOf((string) $content->parent_id);
            }

            if (isset($paths[$id])) {
                $tags[] = PageTags::url((string) $paths[$id]);
            }

            foreach ($credits[$id] ?? [] as $credit) {
                $tags[] = PageTags::author($credit->id);
            }

            if ($content->published_at !== null) {
                array_push($tags, ...PageTags::dates(SiteDates::inSite($content->published_at)));
            }

            $footprints[$id] = $tags;
        }

        foreach (DB::table('content_terms')
            ->join('terms', 'terms.id', '=', 'content_terms.term_id')
            ->whereIn('content_terms.content_id', $ids)
            ->where('content_terms.scope', 'published')
            ->get(['content_terms.content_id', 'terms.id', 'terms.taxonomy']) as $term) {
            $footprints[(string) $term->content_id][] = PageTags::filedUnder((string) $term->taxonomy, (string) $term->id);
        }

        return $footprints;
    }

    /**
     * Each term's: the term as pages show it (its name and address), the
     * list of what is filed under it, and its address; none for a term that
     * no longer exists.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, list<string>>
     */
    public static function terms(array $ids): array
    {
        $footprints = array_fill_keys($ids, []);

        if ($ids === [] || ! self::read()) {
            return $footprints;
        }

        $paths = DB::table('paths')->where('target_type', 'term')->whereIn('target_id', $ids)->where('is_current', true)->pluck('path', 'target_id');

        foreach (DB::table('terms')->whereIn('id', $ids)->get(['id', 'taxonomy']) as $term) {
            $id = (string) $term->id;
            $footprints[$id] = [PageTags::term($id), PageTags::filedUnder((string) $term->taxonomy, $id), PageTags::termsOf((string) $term->taxonomy)];

            if (isset($paths[$id])) {
                $footprints[$id][] = PageTags::url((string) $paths[$id]);
            }
        }

        return $footprints;
    }

    /**
     * A term's footprint and each footprint of the terms beneath it, whose
     * addresses hold its slug, keyed by ID.
     *
     * @return array<string, list<string>>
     */
    public static function termAndBeneath(string $termId): array
    {
        return self::read() ? self::terms(app(TermTree::class)->descendantIds($termId)) : [];
    }

    /**
     * A person's: the person as pages show them (their name, bio and
     * address), the list of what they wrote, and its address.
     *
     * @return list<string>
     */
    public static function person(User $user): array
    {
        // One ID in, one footprint out: by position, as PHP keys a numeric ID
        // by integer.
        return current(self::people([(string) $user->id])) ?: [];
    }

    /**
     * A person with no account's (I-3366): as an account's, by the person's ID.
     *
     * @return list<string>
     */
    public static function guest(string $personId): array
    {
        return self::people([$personId])[$personId] ?? [];
    }

    /**
     * Each person's footprint, keyed by their credit's ID: an account's ID,
     * or a person with no account's.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, list<string>>
     */
    public static function people(array $ids): array
    {
        $footprints = array_fill_keys($ids, []);

        if ($ids === [] || ! self::read()) {
            return $footprints;
        }

        // An account's page is kind user, a person with no account's kind person; their IDs never meet (I-3366).
        $paths = DB::table('paths')->whereIn('target_type', ['user', 'person'])->whereIn('target_id', $ids)->where('is_current', true)->pluck('path', 'target_id');

        foreach ($ids as $id) {
            $footprints[$id] = [PageTags::person($id), PageTags::author($id)];

            if (isset($paths[$id])) {
                $footprints[$id][] = PageTags::url((string) $paths[$id]);
            }
        }

        return $footprints;
    }

    /**
     * Whether footprints are read: while the page cache is on, or while a
     * plugin listens for FeedsChanged.
     */
    private static function read(): bool
    {
        return PageCache::enabled() || app(HookDispatcher::class)->wants(FeedsChanged::class);
    }
}

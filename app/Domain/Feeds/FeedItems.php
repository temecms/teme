<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Addresses\ListingTypes;
use App\Domain\People\Credits;
use App\Domain\Site\ListingViews;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The reads behind a feed, and the rules for what it may hold (I-299): only
 * published, live items of the types the subject's page lists (I-3298), each
 * read from its published revision and never from a working one. A feed that
 * can show a draft's title is a leak; every query here starts from
 * ListingViews::archive() or joins the published revision.
 */
final class FeedItems
{
    public function __construct(private TaxonomyRegistry $taxonomies) {}

    /**
     * The types this subject's feed holds (I-3298): the main feed the types
     * Reading ticks; a term's every public type that uses its taxonomy; an
     * author's the public types with an archive, as their pages list them;
     * a type's archive page's, that type alone.
     *
     * @return list<string>
     */
    public function types(FeedSubject $subject): array
    {
        return match ($subject->kind) {
            FeedSubject::TERM => ListingTypes::usingTaxonomy((string) $subject->taxonomy),
            FeedSubject::AUTHOR => ListingTypes::withArchive(),
            FeedSubject::TYPE => [(string) $subject->type],
            default => ListingTypes::onHome(),
        };
    }

    /**
     * The IDs of the newest items of this subject, newest first, in the
     * subject's language alone (I-3360).
     *
     * @return list<string>
     */
    public function ids(FeedSubject $subject, int $limit): array
    {
        $types = $this->types($subject);

        if ($types === []) {
            return [];
        }

        // Only public items go beyond the site's own pages (I-1700).
        $items = ListingViews::inLanguage(ListingViews::archive($types)->where('contents.visibility', 'public'), $subject->language);

        if ($subject->kind === FeedSubject::TERM) {
            $items->whereExists(fn (Builder $sub) => $sub->selectRaw('1')
                ->from('content_terms')
                ->whereColumn('content_terms.content_id', 'contents.id')
                ->where('content_terms.scope', 'published')
                ->where('content_terms.term_id', $subject->termId));
        } elseif ($subject->kind === FeedSubject::AUTHOR && $subject->credit !== null) {
            // Every item that credits them, by a byline or as its owner with none (I-3366).
            [$sql, $bindings] = app(Credits::class)->itemIdsSql($subject->credit);
            $items->whereRaw("contents.id IN ({$sql})", $bindings);
        }

        return $items->orderByDesc('contents.published_at')->orderByDesc('contents.id')
            ->limit($limit)
            ->pluck('contents.id')->map(fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * These items' published revisions, keyed by item ID: title, document,
     * fields and terms (JSON as stored), the publication time and the time
     * the published revision was saved. An item that stopped being published
     * and live since ids() is left out.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, object>
     */
    public function rows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('contents')
            ->join('content_revisions', 'content_revisions.id', '=', 'contents.published_revision_id')
            ->whereIn('contents.id', $ids)
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->where('contents.published_at', '<=', now())
            ->where('contents.visibility', 'public')
            ->get(['contents.id', 'contents.type', 'contents.author_id', 'contents.published_at', 'contents.guid', 'content_revisions.title', 'content_revisions.document', 'content_revisions.fields', 'content_revisions.terms', 'content_revisions.created_at as revised_at'])
            ->keyBy(fn (object $row): string => (string) $row->id)
            ->all();
    }

    /**
     * The stored path of each item that has a current address, keyed by item ID.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, string>
     */
    public function paths(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('paths')->where('target_type', 'content')->where('is_current', true)->whereIn('target_id', $ids)
            ->pluck('path', 'target_id')->map(fn (mixed $path): string => (string) $path)->all();
    }

    /**
     * The name of each of these terms whose taxonomy is public, keyed by term ID.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, string>
     */
    public function terms(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (DB::table('terms')->whereIn('id', $ids)->get(['id', 'name', 'taxonomy']) as $term) {
            if ($this->taxonomies->has((string) $term->taxonomy) && $this->taxonomies->get((string) $term->taxonomy)->public) {
                $names[(string) $term->id] = (string) $term->name;
            }
        }

        return $names;
    }
}

<?php

/**
 * One page of live, public items for the API's list (D5, D19): newest
 * first, oldest first, or in their order under a parent, by cursor,
 * filtered by type, term, author, dates, parent and language, each from its
 * published revision. One statement for the page;
 * its terms, authors and images in one statement each (ItemShapes).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api\Items;

use App\Domain\Api\Cursor;
use App\Domain\Api\Live;
use App\Domain\Api\Shapes\Dates;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\SiteLanguages;
use App\Domain\People\Credits;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final readonly class ItemsQuery
{
    /**
     * The page's rows, each with its published title and fields (null for a
     * locked item, never read), its current address and whether it is
     * locked, and the cursor after the last one while more follow.
     *
     * @return array{rows: list<object>, next: ?string}
     */
    public function page(ItemFilters $filters): array
    {
        $this->tag($filters);
        $descending = $filters->newestFirst();
        $byPosition = $filters->sort === ItemFilters::POSITION;

        [$credited, $creditedBindings] = $filters->author === null ? ['', []] : app(Credits::class)->itemIdsSql($filters->author);

        // One language's items, the default's unless one is asked for (I-3360); one item is read in any shown language.
        $items = Live::items($filters->types);
        $items = $filters->language === null ? app(SiteLanguages::class)->whereDefault($items, 'contents.language') : $items->where('contents.language', $filters->language);
        $rows = Live::unlessLocked(Live::withLocked($items), 'revision.fields')
            ->join('content_revisions as revision', 'revision.id', '=', 'contents.published_revision_id')
            // Compared as text, as ListingViews::itemsById() does, so the paths index answers the join.
            ->leftJoin('paths', fn (JoinClause $join) => $join->on('paths.target_id', '=', DB::raw('contents.id::text'))->where('paths.target_type', 'content')->where('paths.is_current', true))
            ->when($filters->termId !== null, fn (Builder $query) => $query->whereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('content_terms')
                ->whereColumn('content_terms.content_id', 'contents.id')->where('content_terms.scope', 'published')->where('content_terms.term_id', $filters->termId)))
            // Every item that credits them, by a byline or as its owner with none (I-3366).
            ->when($filters->author !== null, fn (Builder $query) => $query->whereRaw("contents.id IN ({$credited})", $creditedBindings))
            ->when($filters->since !== null, fn (Builder $query) => $query->where('contents.published_at', '>=', $filters->since))
            ->when($filters->before !== null, fn (Builder $query) => $query->where('contents.published_at', '<', $filters->before))
            // The live children of a live item; any other ID's are none, as an unknown one's (I-3363).
            ->when($filters->parentId !== null, fn (Builder $query) => $query->where('contents.parent_id', $filters->parentId)
                ->whereIn('contents.parent_id', Live::items()->select('contents.id')->where('contents.id', $filters->parentId)))
            ->when($filters->after !== null && $byPosition, fn (Builder $query) => $query->whereRaw(
                '(contents.position, contents.id) > (?::int, ?)',
                [$filters->after?->key, $filters->after?->id],
            ))
            ->when($filters->after !== null && ! $byPosition, fn (Builder $query) => $query->whereRaw(
                '(contents.published_at, contents.id) '.($descending ? '<' : '>').' (?::timestamptz, ?)',
                [$filters->after?->key, $filters->after?->id],
            ))
            ->when($byPosition, fn (Builder $query) => $query->orderBy('contents.position'))
            ->when(! $byPosition, fn (Builder $query) => $query->orderBy('contents.published_at', $descending ? 'desc' : 'asc'))
            ->orderBy('contents.id', $descending ? 'desc' : 'asc')
            ->limit($filters->limit + 1)
            ->addSelect(ItemShapes::COLUMNS)
            ->get()
            ->all();

        $more = count($rows) > $filters->limit;
        $rows = array_slice($rows, 0, $filters->limit);
        $last = end($rows);

        return [
            'rows' => $rows,
            'next' => $more && $last !== false ? Cursor::after($filters->sort, $byPosition ? (string) $last->position : Dates::utc((string) $last->published_at), (string) $last->id) : null,
        ];
    }

    /**
     * Notes the lists the page is drawn from (D18): each type's it may hold,
     * and the term's or the person's when it is filtered by one.
     */
    private function tag(ItemFilters $filters): void
    {
        $record = app(RenderRecord::class);
        $record->tag(...array_map(PageTags::listOf(...), $filters->types ?? Live::types()));

        if ($filters->termId !== null && $filters->termTaxonomy !== null) {
            $record->tag(PageTags::filedUnder($filters->termTaxonomy, $filters->termId), PageTags::term($filters->termId));
        }

        if ($filters->parentId !== null) {
            $record->tag(PageTags::childrenOf($filters->parentId));
        }

        if ($filters->author !== null && $filters->author->id !== '0') {
            $record->tag(PageTags::author($filters->author->id), PageTags::person($filters->author->id));
        }
    }
}

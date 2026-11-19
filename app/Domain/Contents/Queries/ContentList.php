<?php

/**
 * Every Posts and Pages list: one page of rows, by number up to the cap and
 * by keyset past it, so a deep page costs what page one does (I-140, I-3421),
 * with the tab counts from content_counts. A filtered list's total is capped.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Comments\Queries\CommentScope;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\Visibility;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Media\MediaUrls;
use App\Domain\Site\SiteLanguage;
use App\Domain\Support\Queries\Cursor;
use App\Domain\Support\Queries\ListPages;
use App\Domain\Taxonomy\Queries\TermTree;
use App\Domain\Themes\TemplateChoice;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ContentList
{
    /** Rows per page when the request asks for a size the lists do not offer. */
    private const int PAGE = 10;

    /** @var list<int> The page sizes a person may choose (lists.per_page). */
    private const array PAGE_SIZES = [10, 25, 50, 100];

    /** @var array<string, array{0: string, 1: string}> sort => [column, default direction] */
    private const array SORTS = [
        'updated' => ['updated_at', 'desc'],
        'title' => ['title', 'asc'],
        'published' => ['published_at', 'desc'],
        'trashed' => ['deleted_at', 'desc'],
        'scheduled' => ['scheduled_for', 'asc'],
        // The author's name folded for sorting (AuthorSort), not author_id,
        // which would order by who signed up first.
        'author' => ['author_sort', 'asc'],
    ];

    /** @var array<string, string> Every tab, always shown, in this order (I-440). */
    private const array TABS = [
        'all' => 'All',
        'mine' => 'Mine',
        'published' => 'Published',
        'private' => 'Private',
        'drafts' => 'Drafts',
        'review' => 'In review',
        'scheduled' => 'Scheduled',
        'trash' => 'Trash',
    ];

    /**
     * At or above this share of a type's live items, a term filter walks the
     * list's own index; below it, PostgreSQL's own plan, starting from the
     * term's index, is already right. Measured at a million items (1.3d
     * addendum).
     */
    private const float DENSE_SHARE = 0.005;

    /** @var list<string> Every term ID the request being built filters by. */
    private array $filterTermIds = [];

    public function __construct(private readonly TermTree $tree) {}

    /**
     * One page of the list the request describes, its place among the pages
     * (ListPages; `newer` and `older` repeat its cursors for the bulk runs
     * that walk a list by keyset), the tabs with their counts, whether a filter or search narrowed it,
     * and the sort and direction in force, which differ from the request's
     * when the tab does not offer the sort asked for. Each row's `comments`
     * is its approved and waiting counts when the request asks for them
     * (1.8c's D21), else null. Each row's `discussion` is its state and
     * whether the person may publish it, by the same rule, else null. Each
     * row's `viewUrl` is its live address, or null for anything not live.
     * Each row's `parent` is its parent's ID and title, or null; `template`
     * the name of the template it chose, or null for the default; `fields`
     * its values for the type's list fields (ListFields), as cells show them.
     *
     * @return array{rows: list<array<string, mixed>>, paging: array{number: int|null, last: int, more: bool, from: int|null, to: int|null, total: int|null, newer: string|null, older: string|null}, newer: string|null, older: string|null, tabs: list<array{key: string, label: string, count: int}>, filtered: bool, sort: string, direction: string}
     */
    public function page(ContentListRequest $request): array
    {
        $this->filterTermIds = [];

        $tab = array_key_exists($request->tab, self::TABS) ? $request->tab : 'all';
        $perPage = in_array($request->perPage, self::PAGE_SIZES, true) ? $request->perPage : self::PAGE;
        $sort = $this->sortFor($tab, $request->sort, $request->authorId);
        [$column, $defaultDirection] = self::SORTS[$sort];
        $direction = in_array($request->direction, ['asc', 'desc'], true) ? $request->direction : $defaultDirection;

        // A before that does not decode (a hand-edited link) is no before: page one.
        $before = Cursor::decode($request->before, 2);
        $backwards = $before !== null;
        $cursor = $before ?? Cursor::decode($request->after, 2);
        $scanDirection = $backwards ? ($direction === 'asc' ? 'desc' : 'asc') : $direction;

        // Read before the rows, on every page whatever it holds, so a deep page
        // costs what page one does (B-173).
        $abilities = ContentAbilities::of($request->viewerId, $request->type);
        [$canEdit, $canEditBindings] = $abilities->canEditSql('contents.author_id', $request->viewerId);

        $query = DB::table('contents')
            ->leftJoin('users', 'users.id', '=', 'contents.author_id')
            ->where('contents.type', $request->type)
            ->select([
                'contents.id', 'contents.title', 'contents.slug', 'contents.status', 'contents.author_id',
                'contents.updated_at', 'contents.published_at', 'contents.deleted_at', 'contents.working_revision_id',
                // The Scheduled tab's and the author sort's cursors are built
                // from these.
                'contents.scheduled_for', 'contents.author_sort', 'contents.comment_status', 'contents.visibility',
                'contents.language', 'contents.translation_group', 'contents.parent_id', 'users.name as author_name',
            ])
            // The parent's title for the Parent column, in the same statement (I-3363).
            ->selectRaw('(SELECT parents.title FROM contents AS parents WHERE parents.id = contents.parent_id) AS parent_title')
            // Whether the viewer may edit each row: ContentPolicy::update's
            // rule as SQL (ContentAbilities), so fifty rows ask no policy.
            ->selectRaw("({$canEdit}) AS can_edit", $canEditBindings);

        $this->applyTab($query, $tab, $request->viewerId);
        $filtered = $this->applyFilters($query, $request, $tab);

        // A list narrowed by nothing but its tab has its exact total in the
        // tab's count; a narrowed one is counted up to the cap, on every page.
        $tabs = $this->tabs($request->type, $request->viewerId);
        $exactTotal = $filtered ? null : (int) collect($tabs)->firstWhere('key', $tab)['count'];
        $cappedCount = $filtered ? ListPages::count($query, $perPage) : 0;
        $pageNumber = $cursor === null ? ListPages::requested($request->page) : null;

        if ($cursor !== null) {
            $operator = $scanDirection === 'asc' ? '>' : '<';
            $query->whereRaw("(contents.{$column}, contents.id) {$operator} (?, ?)", $cursor);
        } elseif ($pageNumber > 1) {
            $query->offset(($pageNumber - 1) * $perPage);
        }

        $rows = $this->fetch(
            $query
                ->orderBy("contents.{$column}", $scanDirection)
                ->orderBy('contents.id', $scanDirection)
                ->limit($perPage + 1),
            $request->type,
        );

        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage);

        if ($backwards) {
            $rows = $rows->reverse()->values();
        }

        $terms = $this->termsFor($rows->pluck('id')->all());
        $taxonomies = array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, app(TaxonomyRegistry::class)->forType($request->type));
        $revisionFields = $this->revisionFieldsFor($rows->pluck('id')->all());
        $images = $this->featuredImagesFor($revisionFields);
        $listFields = ListFields::of($request->type);
        $templates = TemplateChoice::declared($request->type);
        $addresses = $this->addressesFor($rows, $request->type);
        // Every row, not only the scheduled ones, so every page of a list
        // costs the same queries whatever it holds (the benchmark holds it).
        $scheduleIssues = $this->scheduleIssuesFor($rows->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all());
        $reviews = ReviewQueue::pendingFor($rows->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(), $request->viewerId, [$request->type => $abilities]);
        $comments = $request->withComments ? $this->commentsFor($rows, $request->type, $request->viewerId) : [];
        $versions = $this->versionsFor($rows, $request->type);
        $first = $rows->first();
        $last = $rows->last();
        $languages = app(SiteLanguages::class);
        $newer = $first !== null && ($backwards ? $hasMore : $cursor !== null) ? Cursor::encode([$first->{$column}, $first->id]) : null;
        $older = $last !== null && ($backwards ? true : $hasMore) ? Cursor::encode([$last->{$column}, $last->id]) : null;

        return [
            'rows' => $rows->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'slug' => (string) $row->slug,
                'status' => (string) $row->status,
                'visibility' => (string) $row->visibility,
                // Its language's tag (I-3360).
                'language' => $languages->of($row->language === null ? null : (string) $row->language),
                // Its group's versions by language, for the list's language marks; null on a site with one language.
                'versions' => $versions === null ? null : ($row->translation_group === null
                    ? [$languages->of($row->language === null ? null : (string) $row->language) => $versions['alone'][(string) $row->id] ?? 'there']
                    : $versions['groups'][(string) $row->translation_group] ?? []),
                'trashed' => $row->deleted_at !== null,
                'authorId' => $row->author_id === null ? null : (int) $row->author_id,
                'authorName' => $row->author_name,
                'terms' => array_combine($taxonomies, array_map(fn (string $key): array => $terms[$row->id][$key] ?? [], $taxonomies)),
                'featuredImage' => $images[$row->id] ?? null,
                'parent' => $row->parent_id === null ? null : ['id' => (string) $row->parent_id, 'title' => (string) $row->parent_title],
                // The template's name as the active theme gives it; null for the theme's default.
                'template' => $this->templateOf($revisionFields[(string) $row->id] ?? [], $templates),
                'fields' => ListFields::cells($listFields, $revisionFields[(string) $row->id] ?? []),
                'viewUrl' => $addresses[(string) $row->id] ?? null,
                'updatedAt' => $row->updated_at,
                'publishedAt' => $row->published_at,
                'workingRevisionId' => $row->working_revision_id,
                'scheduledFor' => $row->scheduled_for === null ? null : CarbonImmutable::parse($row->scheduled_for)->toIso8601String(),
                'review' => $reviews[$row->id] ?? null,
                'scheduleIssue' => $row->status === 'scheduled' ? ($scheduleIssues[$row->id] ?? null) : null,
                // Other people's unpublished work is listed, never editable (I-409).
                'canEdit' => (bool) $row->can_edit,
                'comments' => $comments[(string) $row->id] ?? null,
                // Quick and Bulk edit's Discussion, by the rule the Comments column follows (I-688).
                'discussion' => $request->withComments
                    ? ['value' => (string) $row->comment_status, 'canSet' => $abilities->canPublish($row->author_id === null ? null : (int) $row->author_id, $request->viewerId)]
                    : null,
            ])->values()->all(),
            'paging' => ListPages::describe($pageNumber, $perPage, $rows->count(), $exactTotal, $cappedCount, $newer, $older),
            'newer' => $newer,
            'older' => $older,
            'tabs' => $tabs,
            'filtered' => $filtered,
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /**
     * The versions of every row's group, by group and language, each there
     * or out of date (D9), in one statement for the page however many rows
     * it holds; a row alone has only itself. Null when the site has one
     * language or the type isn't translated (D25), and no statement is run.
     *
     * @param  Collection<int, stdClass>  $rows
     *
     * @return array{groups: array<string, array<string, 'there'|'outOfDate'>>, alone: array<string, 'there'|'outOfDate'>}|null
     */
    private function versionsFor(Collection $rows, string $type): ?array
    {
        $languages = app(SiteLanguages::class);

        if (! $languages->isMultilingual() || ! $languages->translates($type)) {
            return null;
        }

        $groups = $rows->pluck('translation_group')->filter()->map(fn (mixed $group): string => (string) $group)->unique()->values()->all();
        $alone = $rows->filter(fn (object $row): bool => $row->translation_group === null)->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all();
        $found = ['groups' => [], 'alone' => []];

        if ($groups === [] && $alone === []) {
            return $found;
        }

        // D9's out-of-date rule, as TranslationStatus::forGroup() writes it, for many groups at once.
        $marks = DB::select(<<<'SQL'
            SELECT c.id, c.translation_group, c.language,
                   COALESCE(src.published_revision_id IS NOT NULL
                       AND src.published_revision_id <> sr.id
                       AND pub.created_at > sr.created_at, false) AS out_of_date
            FROM contents c
            JOIN content_revisions r ON r.id = c.working_revision_id
            LEFT JOIN content_revisions sr ON sr.id = r.source_revision_id
            LEFT JOIN contents src ON src.id = sr.content_id AND src.deleted_at IS NULL
            LEFT JOIN content_revisions pub ON pub.id = src.published_revision_id
            WHERE (c.translation_group = ANY(?::text[]) OR c.id = ANY(?::text[])) AND c.deleted_at IS NULL
            SQL, ['{'.implode(',', $groups).'}', '{'.implode(',', $alone).'}']);

        foreach ($marks as $mark) {
            $state = (bool) $mark->out_of_date ? 'outOfDate' : 'there';

            if ($mark->translation_group === null) {
                $found['alone'][(string) $mark->id] = $state;
            } else {
                $found['groups'][(string) $mark->translation_group][$languages->of($mark->language === null ? null : (string) $mark->language)] = $state;
            }
        }

        return $found;
    }

    /**
     * The live address of each row that has one, as a site-relative URL: a
     * published item neither private nor in the trash, with a current path
     * (I-3130). One read of the paths on every page, live rows or none, so
     * every page costs the same.
     *
     * @param  Collection<int, stdClass>  $rows
     *
     * @return array<string, string>
     */
    private function addressesFor(Collection $rows, string $type): array
    {
        $live = $rows
            ->filter(fn (object $row): bool => $row->deleted_at === null && $row->status === 'published' && $row->visibility !== Visibility::Private->value)
            ->map(fn (object $row): string => (string) $row->id)
            ->values()
            ->all();

        return DB::table('paths')
            ->where('target_type', 'content')
            ->whereIn('target_id', $live === [] ? [''] : $live)
            ->where('is_current', true)
            ->pluck('path', 'target_id')
            ->map(fn (mixed $path): string => Addresses::toUrl((string) $path, $type))
            ->all();
    }

    /**
     * Runs the rows query. PostgreSQL cannot estimate how many items a set of
     * terms matches, and at a million rows a wrong guess means sorting the
     * whole table. When the maintained counts say the matches are dense,
     * walking the list index in order is always cheapest, so sorting and
     * sequential scans are turned off for this one query, in its own
     * transaction, so the settings end with it.
     *
     * @return Collection<int, stdClass>
     */
    private function fetch(Builder $query, string $type): Collection
    {
        if (! $this->termMatchesAreDense($type)) {
            return $query->get();
        }

        return DB::transaction(function () use ($query): Collection {
            DB::statement('SET LOCAL enable_sort = off');
            DB::statement('SET LOCAL enable_seqscan = off');

            return $query->get();
        });
    }

    private function termMatchesAreDense(string $type): bool
    {
        if ($this->filterTermIds === []) {
            return false;
        }

        $counts = ContentCounts::for($type);
        $live = ($counts['draft'] ?? 0) + ($counts['review'] ?? 0) + ($counts['scheduled'] ?? 0) + ($counts['published'] ?? 0) + ($counts['private'] ?? 0);
        $matches = (int) DB::table('terms')->whereIn('id', $this->filterTermIds)->sum('working_count');

        return $live > 0 && $matches >= $live * self::DENSE_SHARE;
    }

    /**
     * The sort the tab admits for what was asked, or the tab's own default.
     * No index covers author_id and author_sort together, and every row
     * has one author anyway, so the author sort is not offered on Mine or
     * while the Author filter is set: it falls back as an unknown sort does.
     */
    private function sortFor(string $tab, string $requested, ?int $authorId): string
    {
        if ($tab === 'trash') {
            return 'trashed';
        }

        if ($requested === 'author' && ($tab === 'mine' || $authorId !== null)) {
            $requested = 'updated';
        }

        // Soonest first by default on the Scheduled tab, and nowhere else.
        if ($tab === 'scheduled') {
            return in_array($requested, ['title', 'author'], true) ? $requested : 'scheduled';
        }

        if ($requested === 'published') {
            return $tab === 'published' ? 'published' : 'updated';
        }

        return in_array($requested, ['updated', 'title', 'author'], true) ? $requested : 'updated';
    }

    private function applyTab(Builder $query, string $tab, int $viewerId): void
    {
        if ($tab === 'trash') {
            $query->whereNotNull('contents.deleted_at');

            return;
        }

        $query->whereNull('contents.deleted_at');

        match ($tab) {
            'published' => $query->where('contents.status', 'published')->where('contents.visibility', '<>', 'private'),
            'private' => $query->where('contents.status', 'published')->where('contents.visibility', 'private'),
            'drafts' => $query->where('contents.status', 'draft'),
            'review' => $query->where('contents.status', 'review'),
            'scheduled' => $query->where('contents.status', 'scheduled'),
            'mine' => $query->where('contents.author_id', $viewerId),
            default => null,
        };
    }

    private function applyFilters(Builder $query, ContentListRequest $request, string $tab): bool
    {
        $filtered = false;

        if ($request->search !== null && trim($request->search) !== '') {
            if ($request->searchIn === 'body') {
                // The published text only: search_tsv follows the published
                // revision (SearchText).
                $query->whereRaw('contents.search_tsv @@ plainto_tsquery(?::regconfig, ?)', [app(SiteLanguage::class)->searchConfig(), trim($request->search)]);
            } else {
                $query->where('contents.title', 'ilike', '%'.addcslashes(trim($request->search), '%_\\').'%');
            }

            $filtered = true;
        }

        if ($request->authorId !== null) {
            $query->where('contents.author_id', $request->authorId);
            $filtered = true;
        }

        // Unlike the In review tab, which reads the status, this reads the
        // reviews, so a live article whose update waits is listed too. A
        // filter like the author's: no count, the same queries on every page.
        if (in_array($request->review, ['pending', 'submitted'], true)) {
            $query->whereExists(fn (Builder $sub) => $sub->selectRaw('1')
                ->from('content_reviews')
                ->whereColumn('content_reviews.content_id', 'contents.id')
                ->whereNull('content_reviews.decided_at')
                ->when($request->review === 'submitted', fn (Builder $mine) => $mine->where('content_reviews.submitted_by', $request->viewerId)));
            $filtered = true;
        }

        $termIds = [];

        // One filter per taxonomy; a nesting taxonomy's filter includes the
        // term's descendants, as the category filter always has.
        foreach ($request->terms as $taxonomy => $termId) {
            $termIds[] = $this->tree->nests($taxonomy) ? $this->tree->descendantIds($termId) : [$termId];
        }

        foreach ($termIds as $ids) {
            $this->filterTermIds = [...$this->filterTermIds, ...$ids];
            $query->whereExists(fn (Builder $sub) => $sub->selectRaw('1')
                ->from('content_terms')
                ->whereColumn('content_terms.content_id', 'contents.id')
                ->where('content_terms.scope', 'working')
                ->whereIn('content_terms.term_id', $ids));
            $filtered = true;
        }

        // One of the site's languages: D1's predicate, NULL for the default (I-3360); none for a type that isn't translated (D25).
        $languages = app(SiteLanguages::class);

        if ($request->language !== null && $languages->has($request->language) && $languages->translates($request->type)) {
            $stored = $languages->stored($request->language);
            $stored === null ? $query->whereNull('contents.language') : $query->where('contents.language', $stored);
            $filtered = true;
        }

        // "Missing a version in…": no live item of its group is in that language
        // (docs/proposals/multilingual-screens.md); an item alone has no version but its own.
        $missing = $request->language !== null && str_starts_with($request->language, 'missing:') ? substr($request->language, 8) : null;

        if ($missing !== null && $languages->has($missing) && $languages->translates($request->type)) {
            $stored = $languages->stored($missing);
            $stored === null ? $query->whereNotNull('contents.language') : $query->where(fn (Builder $own) => $own->whereNull('contents.language')->orWhere('contents.language', '<>', $stored));
            $query->where(fn (Builder $alone) => $alone->whereNull('contents.translation_group')->orWhereNotExists(fn (Builder $version) => $version->selectRaw('1')
                ->from('contents as versions')
                ->whereColumn('versions.translation_group', 'contents.translation_group')
                ->whereNull('versions.deleted_at')
                ->when($stored === null, fn (Builder $in) => $in->whereNull('versions.language'), fn (Builder $in) => $in->where('versions.language', $stored))));
            $filtered = true;
        }

        $dateColumn = $tab === 'published' ? 'contents.published_at' : 'contents.updated_at';

        // The Date filter's bounds, in the site's zone, worked out by ContentListFilters (I-3129).
        if ($request->startsAt !== null) {
            $query->where($dateColumn, '>=', $request->startsAt);
            $filtered = true;
        }

        if ($request->endsBefore !== null) {
            $query->where($dateColumn, '<', $request->endsBefore);
            $filtered = true;
        }

        return $filtered;
    }

    /**
     * The months that hold the tab's items, newest first, with their counts,
     * and the date they are counted by: published on Published, the last
     * change elsewhere, as the tab's Date filter reads (I-3131). Mine reads
     * the viewer's own rows.
     *
     * @return array{by: 'updated'|'published', months: list<array{month: string, count: int}>}
     */
    public function months(string $type, string $tab, int $viewerId): array
    {
        return [
            'by' => $tab === 'published' ? 'published' : 'updated',
            'months' => ContentCounts::months($type, $tab, $tab === 'mine' ? $viewerId : 0),
        ];
    }

    /**
     * Which items' schedules cannot run as things stand: the latest one
     * failed, or is held until someone reschedules it. A failed schedule
     * leaves its item scheduled, so the list must say so.
     *
     * @param  list<string>  $contentIds
     *
     * @return array<string, 'failed'|'held'>
     */
    private function scheduleIssuesFor(array $contentIds): array
    {
        if ($contentIds === []) {
            return [];
        }

        $latest = DB::table('scheduled_publications')
            ->whereIn('content_id', $contentIds)
            ->selectRaw('DISTINCT ON (content_id) content_id, failed_at, held_reason, completed_at, cancelled_at')
            ->orderBy('content_id')->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        $issues = [];

        foreach ($latest as $row) {
            if ($row->failed_at !== null) {
                $issues[(string) $row->content_id] = 'failed';
            } elseif ($row->held_reason !== null && $row->completed_at === null && $row->cancelled_at === null) {
                $issues[(string) $row->content_id] = 'held';
            }
        }

        return $issues;
    }

    /**
     * Each row's approved count, and its waiting count when the viewer looks
     * after its comments (1.8c's D21): one read of the page's rows' counts, so
     * every page costs the same. A viewer of 0 looks after nothing.
     *
     * @param  Collection<int, stdClass>  $rows
     *
     * @return array<string, array{approved: int, waiting: int|null, mine: bool}>
     */
    private function commentsFor(Collection $rows, string $type, int $viewerId): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $stats = DB::table('comment_stats')
            ->whereIn('content_id', $rows->pluck('id')->all())
            ->get(['content_id', 'approved_count', 'pending_count'])
            ->keyBy('content_id');
        $viewer = $viewerId === 0 ? null : User::query()->find($viewerId);
        $scope = $viewer === null ? null : CommentScope::forViewer($viewer);
        $comments = [];

        foreach ($rows as $row) {
            $stat = $stats->get($row->id);
            $mine = $scope?->covers($type, $row->author_id === null ? null : (int) $row->author_id) ?? false;

            $comments[(string) $row->id] = [
                'approved' => (int) ($stat->approved_count ?? 0),
                'waiting' => $mine ? (int) ($stat->pending_count ?? 0) : null,
                'mine' => $mine,
            ];
        }

        return $comments;
    }

    /**
     * @param  list<string>  $contentIds
     *
     * @return array<string, array<string, list<array{id: string, name: string}>>>
     */
    private function termsFor(array $contentIds): array
    {
        if ($contentIds === []) {
            return [];
        }

        $rows = DB::table('content_terms')
            ->join('terms', 'terms.id', '=', 'content_terms.term_id')
            ->whereIn('content_terms.content_id', $contentIds)
            ->where('content_terms.scope', 'working')
            ->orderBy('content_terms.position')
            ->get(['content_terms.content_id', 'terms.id', 'terms.name', 'terms.taxonomy']);

        $terms = [];

        foreach ($rows as $row) {
            $terms[$row->content_id][$row->taxonomy][] = ['id' => (string) $row->id, 'name' => (string) $row->name];
        }

        return $terms;
    }

    /**
     * The fields of each item's working revision, by item, from the
     * revisions' fields column — never the document — in one statement.
     *
     * @param  list<string>  $contentIds
     *
     * @return array<string, array<string, mixed>>
     */
    private function revisionFieldsFor(array $contentIds): array
    {
        if ($contentIds === []) {
            return [];
        }

        return DB::table('contents')
            ->join('content_revisions', 'content_revisions.id', '=', 'contents.working_revision_id')
            ->whereIn('contents.id', $contentIds)
            ->get(['contents.id', 'content_revisions.fields'])
            ->mapWithKeys(fn (object $row): array => [(string) $row->id => (array) json_decode((string) $row->fields, true)])
            ->all();
    }

    /**
     * The featured image of each item, from its working revision's fields,
     * with one lookup of the images.
     *
     * @param  array<string, array<string, mixed>>  $revisionFields
     *
     * @return array<string, array{id: string, title: string, thumbUrl: string|null, alt: string|null, decorative: bool}>
     */
    private function featuredImagesFor(array $revisionFields): array
    {
        $uses = collect($revisionFields)
            ->map(fn (array $fields): mixed => $fields[BuiltInFields::FEATURED_IMAGE] ?? null)
            ->filter(fn (mixed $use): bool => is_array($use) && is_string($use['id'] ?? null));

        if ($uses->isEmpty()) {
            return [];
        }

        $items = DB::table('media')->whereIn('id', $uses->pluck('id')->unique()->values()->all())->get(['id', 'title', 'variants'])->keyBy('id');
        $images = [];

        foreach ($uses as $contentId => $use) {
            $item = $items[$use['id']] ?? null;

            if ($item !== null) {
                $images[(string) $contentId] = [
                    'id' => (string) $item->id,
                    'title' => (string) $item->title,
                    'thumbUrl' => MediaUrls::variant((array) json_decode((string) $item->variants, true)),
                    'alt' => $use['alt'] ?? null,
                    'decorative' => (bool) ($use['decorative'] ?? false),
                ];
            }
        }

        return $images;
    }

    /**
     * The name of the template an item chose, as the active theme offers it,
     * or its slug when the theme no longer does; null for the default.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, string>  $templates  slug => label
     */
    private function templateOf(array $fields, array $templates): ?string
    {
        $slug = $fields[BuiltInFields::TEMPLATE] ?? null;

        return is_string($slug) && $slug !== '' ? ($templates[$slug] ?? $slug) : null;
    }

    /**
     * Every tab, each with its exact count, even at zero: a tab that never
     * moves is learnt once, and a zero says nothing waits there.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    private function tabs(string $type, int $viewerId): array
    {
        $all = ContentCounts::for($type);
        $mine = $viewerId === 0 ? [] : ContentCounts::for($type, $viewerId);
        // A live article with an update in review is counted under Published,
        // never twice: the review bucket holds only items that are not live.
        $live = fn (array $counts): int => ($counts['draft'] ?? 0) + ($counts['review'] ?? 0) + ($counts['scheduled'] ?? 0) + ($counts['published'] ?? 0) + ($counts['private'] ?? 0);

        $counts = [
            'all' => $live($all),
            'published' => $all['published'] ?? 0,
            'private' => $all['private'] ?? 0,
            'drafts' => $all['draft'] ?? 0,
            'review' => $all['review'] ?? 0,
            'scheduled' => $all['scheduled'] ?? 0,
            'mine' => $live($mine),
            'trash' => $all['trash'] ?? 0,
        ];

        $tabs = [];

        foreach (self::TABS as $key => $label) {
            $tabs[] = ['key' => $key, 'label' => __($label), 'count' => $counts[$key]];
        }

        return $tabs;
    }
}

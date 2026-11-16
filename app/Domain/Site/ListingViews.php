<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardMedia;
use App\Domain\Contents\ContentCounts;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Languages\SiteLanguages;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Domain\People\Queries\People;
use App\Domain\Site\Insertions\PageInsertions;
use App\Domain\Site\ViewModels\ImageRef;
use App\Domain\Site\ViewModels\ListingItem;
use App\Domain\Site\ViewModels\ListingView;
use App\Domain\Site\ViewModels\Pagination;
use App\Domain\Site\ViewModels\Person;
use App\Domain\Site\ViewModels\Subject;
use App\Domain\Site\ViewModels\TermLink;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\PrimaryCategory;
use App\Domain\Taxonomy\TermCounts;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\Manifest;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Listings as their templates see them (I-250): a term's, an author's, a
 * date's or a type's own items of the types each lists (I-3298), newest first, a page at a
 * time, each with its published title, never a newer draft's. Items are
 * built in a few queries for the whole page, never one per item.
 */
final readonly class ListingViews
{
    public function __construct(
        private TaxonomyRegistry $taxonomies,
        private SiteLanguage $language,
        private ActiveTheme $active,
        private CardMedia $media,
        private Breadcrumbs $trail,
        private SettingsReader $settings,
        private FieldViews $fieldViews,
    ) {}

    /**
     * One page of every public type that uses this term's taxonomy, filed under it.
     */
    public function forTerm(Term $term, int $page): ListingView
    {
        // What is filed under it, and the term, whose name heads the page (I-263).
        app(RenderRecord::class)->tag(PageTags::filedUnder((string) $term->taxonomy, (string) $term->id), PageTags::term((string) $term->id));
        $types = ListingTypes::usingTaxonomy((string) $term->taxonomy);
        $items = self::inLanguage(self::archive($types), $term->language)->whereExists(fn (Builder $sub) => $sub->selectRaw('1')
            ->from('content_terms')
            ->whereColumn('content_terms.content_id', 'contents.id')
            ->where('content_terms.scope', 'published')
            ->where('content_terms.term_id', $term->id));

        $url = Addresses::relativeUrl($term);
        [$parent, $children] = $this->family($term, $types);

        return new ListingView(
            new Subject('term', (string) $term->name, self::text($term->description), url: $url, taxonomy: (string) $term->taxonomy, parent: $parent, children: $children),
            $this->page($items, $page),
            $this->pagination($page, self::countsInDefault((string) $term->taxonomy) ? (self::listedInDefault([(string) $term->id], $types)[(string) $term->id] ?? 0) : app(TermCounts::class)->published((string) $term->id, ...$types), $url ?? '/'),
            breadcrumbs: $this->trail->wanted() ? $this->trail->toTerm((string) $term->id) : null,
        );
    }

    /**
     * A hierarchical taxonomy's term's parent, published items or not, and
     * its children that hold a published item of these types (I-3348), by
     * name, at most 100, each at its address now, in a statement each; a
     * flat taxonomy's term has neither, and asks nothing. What is filed
     * under each child is recorded, listed or not.
     *
     * @param  list<string>  $types  the types the taxonomy's pages list
     *
     * @return array{0: TermLink|null, 1: list<TermLink>}
     */
    private function family(Term $term, array $types): array
    {
        $taxonomy = (string) $term->taxonomy;

        if (! $this->taxonomies->has($taxonomy) || ! $this->taxonomies->get($taxonomy)->hierarchical) {
            return [null, []];
        }

        $children = [];

        if ($types !== []) {
            // A taxonomy that isn't translated links a child for the default language's items its archive lists (countsInDefault()).
            $listed = self::countsInDefault($taxonomy)
                ? self::archive($types)->selectRaw('1')
                    ->whereNull('contents.language')
                    ->join('content_terms', 'content_terms.content_id', '=', 'contents.id')
                    ->where('content_terms.scope', 'published')
                    ->whereColumn('content_terms.term_id', 'terms.id')
                : DB::table('term_counts')->selectRaw('1')
                    ->whereColumn('term_counts.term_id', 'terms.id')
                    ->whereIn('term_counts.type', $types)
                    ->where('term_counts.published', '>', 0);

            // Every child comes back, so a page leaving one out still
            // changes when it gains its first published item.
            foreach (self::termsWithPaths()
                ->where('terms.taxonomy', $taxonomy)
                ->where('terms.parent_id', $term->id)
                ->selectRaw('exists('.$listed->toSql().') as listed', $listed->getBindings())
                ->orderByDesc('listed')
                ->orderBy('terms.name')
                ->orderBy('terms.id')
                ->limit(100)
                ->addSelect(['terms.id', 'terms.name', 'terms.taxonomy', 'paths.path'])
                ->get() as $row) {
                app(RenderRecord::class)->tag(PageTags::filedUnder($taxonomy, (string) $row->id));

                if ($row->listed) {
                    $children[] = self::linkOf($row);
                }
            }
        }

        $parent = null;

        if ($term->parent_id !== null) {
            $row = self::termsWithPaths()->where('terms.id', $term->parent_id)->first(['terms.id', 'terms.name', 'terms.taxonomy', 'paths.path']);
            $parent = $row === null ? null : self::linkOf($row);
        }

        return [$parent, $children];
    }

    /**
     * Terms with each one's current address, compared as text as items' are.
     */
    private static function termsWithPaths(): Builder
    {
        return DB::table('terms')->leftJoin('paths', fn ($join) => $join->on('paths.target_id', '=', DB::raw('terms.id::text'))->where('paths.target_type', 'term')->where('paths.is_current', true));
    }

    /**
     * A link to a term read with its address, its page tag recorded so the
     * page changes when the term does.
     */
    private static function linkOf(object $row): TermLink
    {
        app(RenderRecord::class)->tag(PageTags::term((string) $row->id));

        return new TermLink((string) $row->name, $row->path === null ? '' : Addresses::toUrl((string) $row->path), (string) $row->taxonomy);
    }

    /**
     * One page of a type's own archive page (I-3304): its published items, newest first.
     */
    public function forType(string $type, string $path, int $page, ?string $language = null): ListingView
    {
        app(RenderRecord::class)->tag(PageTags::listOf($type));
        $url = Addresses::toUrl($path);
        $plural = app(ContentTypeRegistry::class)->get($type)->plural;
        $items = self::inLanguage(self::archive([$type]), $language);

        return new ListingView(
            new Subject('type', $plural, url: $url),
            $this->page(clone $items, $page),
            $this->pagination($page, $this->count($items, [$type], $language), $url),
            breadcrumbs: $this->trail->wanted() ? $this->trail->to($plural, $url) : null,
        );
    }

    /**
     * One page of the public types with an archive that credit this person:
     * an account or a person with no account, as People::shown() gives them
     * (an account given as itself is read that way first). While no byline
     * touches an account, its list is read as before bylines, straight from
     * its index, and counted from the stored counts (I-3366).
     *
     * @param  User|object{kind: string, id: string, name: string, slug: string, bio: string|null, path: string|null}  $shown
     */
    public function forAuthor(object $shown, int $page, ?string $language = null): ListingView
    {
        if ($shown instanceof User) {
            $shown = app(People::class)->shown([Credit::account((int) $shown->id)])[Credit::account((int) $shown->id)->key()];
        }

        $credit = $shown->kind === Credit::ACCOUNT ? Credit::account((int) $shown->id) : Credit::person($shown->id);
        // What they're credited on, and the person, whose name and bio head the page.
        app(RenderRecord::class)->tag(PageTags::author($credit->id), PageTags::person($credit->id));
        // Plugins granted pages.insert are told whose archive this is (docs/plans/plugin-sockets-2.md).
        app(PageInsertions::class)->author((string) $shown->id);
        $types = ListingTypes::withArchive();
        $url = $shown->path === null ? null : Addresses::toUrl($shown->path);
        // An author has one address, the default language's; their list in another language is it after the prefix (I-3360).
        $url = $url === null || $language === null ? $url : Addresses::toUrl(self::prefixed($language, ltrim($url, '/')));
        $owned = $credit->kind === Credit::ACCOUNT && ! app(Credits::class)->bylinesTouch((int) $credit->id);

        if ($owned) {
            $items = self::inLanguage(self::archive($types)->where('contents.author_id', (int) $credit->id), $language);
            $count = $this->count(clone $items, $types, $language, (int) $credit->id);
        } else {
            [$sql, $bindings] = app(Credits::class)->itemIdsSql($credit);
            $items = self::inLanguage(self::archive($types)->whereRaw("contents.id IN ({$sql})", $bindings), $language);
            $count = self::countUpTo(clone $items, PathResolver::LAST_PAGE * $this->perPage());
        }

        return new ListingView(
            new Subject('author', $shown->name, $shown->bio, url: $url),
            $this->page(clone $items, $page),
            $this->pagination($page, $count, $url ?? '/'),
            breadcrumbs: $this->trail->wanted() ? $this->trail->to($shown->name, $url ?? '') : null,
        );
    }

    /**
     * One page of the public types with an archive published in this year, month or day, on the site's clock.
     */
    public function forDate(int $year, ?int $month, ?int $day, int $page, ?string $language = null): ListingView
    {
        // A day's archive lists part of its month's, which a change dated in it purges.
        app(RenderRecord::class)->tag($month === null ? PageTags::year($year) : PageTags::month($year, $month));
        $zone = SiteDates::inSite('now')->getTimezone();
        $start = CarbonImmutable::create($year, $month ?? 1, $day ?? 1, 0, 0, 0, $zone);
        $end = $day !== null ? $start->addDay() : ($month !== null ? $start->addMonthNoOverflow() : $start->addYear());
        $items = self::inLanguage(self::archive(ListingTypes::withArchive()), $language)
            ->where('contents.published_at', '>=', $start->utc()->toIso8601String())
            ->where('contents.published_at', '<', $end->utc()->toIso8601String());

        $title = match (true) {
            $day !== null => SiteDates::show($start, $this->language->tag()),
            $month !== null => SiteDates::show($start, $this->language->tag(), 'MMMM YYYY'),
            default => (string) $year,
        };
        $path = self::prefixed($language, implode('/', array_filter([sprintf('%04d', $year), $month === null ? null : sprintf('%02d', $month), $day === null ? null : sprintf('%02d', $day)])));
        $url = Addresses::toUrl($path);

        return new ListingView(
            new Subject('date', $title, url: $url),
            $this->page(clone $items, $page),
            $this->pagination($page, self::countUpTo(clone $items, PathResolver::LAST_PAGE * $this->perPage()), $url),
            breadcrumbs: $this->trail->wanted() ? $this->trail->to($title, $url) : null,
        );
    }

    /**
     * How many items a listing page shows: the site's Posts per page, else
     * the active theme's own number, else the default when no theme is
     * usable (I-440).
     */
    public function perPage(): int
    {
        $site = $this->settings->int('reading', 'posts_per_page');

        return $site > 0 ? $site : ($this->active->get()?->manifest?->perPage() ?? Manifest::PER_PAGE);
    }

    /**
     * A listing's pages, from its item count and its address.
     */
    public function pagination(int $page, int $count, string $base): Pagination
    {
        $total = max(1, (int) ceil($count / $this->perPage()));

        return new Pagination(
            $page,
            $total,
            $page > 1 ? Pagination::urlFor($base, $page - 1) : null,
            $page < $total ? Pagination::urlFor($base, $page + 1) : null,
            $base,
        );
    }

    /**
     * The items with these IDs, in this order, from their published
     * revisions; an ID that isn't published and live is left out.
     *
     * @param  list<string>  $ids
     *
     * @return list<ListingItem>
     */
    public function items(array $ids): array
    {
        return array_values($this->itemsById($ids));
    }

    /**
     * The same items, keyed by ID, for a caller that pairs each with something of its own.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, ListingItem>
     */
    public function itemsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // Each item asked for, shown or not (D2).
        app(RenderRecord::class)->tag(...array_map(PageTags::content(...), $ids));

        // Compared as text: contents.id is char(26) and paths.target_id
        // varchar(26), and compared as char the cast falls on paths, whose
        // index then can't answer the join (1.6h's benchmark).
        $rows = DB::table('contents')
            ->join('content_revisions', 'content_revisions.id', '=', 'contents.published_revision_id')
            ->leftJoin('paths', fn ($join) => $join->on('paths.target_id', '=', DB::raw('contents.id::text'))->where('paths.target_type', 'content')->where('paths.is_current', true))
            ->whereIn('contents.id', $ids)
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            // Private items are read in the admin, never here (I-1700).
            ->where('contents.visibility', '<>', 'private')
            ->get(['contents.id', 'contents.type', 'contents.language', 'contents.author_id', 'contents.published_at', 'contents.fields', 'content_revisions.title', 'paths.path', 'contents.visibility'])
            ->keyBy('id');

        $fields = $rows->map(fn (object $row): array => (array) json_decode((string) $row->fields, true))->all();
        $kickers = $this->kickers($rows->all(), $fields);
        $authors = $this->authorsOf($rows->keys()->map(fn (mixed $id): string => (string) $id)->all());
        $imageIds = array_values(array_filter(array_map(fn (array $item): ?string => is_array($item[BuiltInFields::FEATURED_IMAGE] ?? null) ? (string) ($item[BuiltInFields::FEATURED_IMAGE]['id'] ?? '') : null, $fields)));
        // A protected item's card shows none of its fields (I-1700).
        $cards = [];

        foreach ($rows as $id => $row) {
            if ($row->visibility !== 'protected') {
                $cards[] = ['id' => (string) $id, 'type' => (string) $row->type, 'fields' => $fields[$id], 'language' => $row->language];
            }
        }

        // Every card's image and every image its fields name in one
        // statement, remembered for the rest of the request, so each card's
        // <x-teme::image> draws it from memory (issue 30).
        $images = array_keys($this->media->forIds([...$imageIds, ...$this->fieldViews->mediaIds($cards)]));
        $views = $this->fieldViews->forCards($cards);
        $items = [];

        foreach ($ids as $id) {
            $row = $rows->get($id);

            if ($row === null) {
                continue;
            }

            $published = SiteDates::forView((string) $row->published_at);
            $protected = $row->visibility === 'protected';
            $featured = $fields[$id][BuiltInFields::FEATURED_IMAGE] ?? null;

            $items[(string) $id] = new ListingItem(
                title: (string) $row->title,
                url: $row->path === null ? '' : Addresses::toUrl((string) $row->path, (string) $row->type),
                // A protected item's card shows its title, never its words or picture (I-1700).
                standfirst: $protected ? null : self::text($fields[$id][BuiltInFields::EXCERPT] ?? null),
                kicker: $kickers[$id] ?? null,
                authors: $authors[(string) $id] ?? [],
                published: $published,
                publishedText: SiteDates::show($published, $this->language->of($row->language)),
                image: ! $protected && is_array($featured) && in_array((string) ($featured['id'] ?? ''), $images, true)
                    ? new ImageRef((string) $featured['id'], self::text($featured['alt'] ?? null), (bool) ($featured['decorative'] ?? false))
                    : null,
                type: (string) $row->type,
                fieldViews: $views[(string) $id] ?? [],
            );
        }

        return $items;
    }

    /**
     * Each item's credits as pages show them, by content ID, in order, read
     * in one statement (Credits::shown()): a name, the address of their
     * articles, and their bio, or null. An item that credits no one has none.
     *
     * @param  list<string>  $contentIds
     *
     * @return array<string, list<Person>>
     */
    public function authorsOf(array $contentIds): array
    {
        return array_map($this->people(...), app(Credits::class)->shown($contentIds));
    }

    /**
     * Credits::shown()'s rows for one item as pages show them.
     *
     * @param  list<object{kind: string, id: string, name: string, slug: string, bio: string|null, path: string|null}>  $shown
     *
     * @return list<Person>
     */
    public function people(array $shown): array
    {
        return array_map(fn (object $person): Person => new Person($person->name, $person->path === null ? '' : Addresses::toUrl($person->path), $person->bio), $shown);
    }

    /**
     * Links to these terms, keyed by ID, each at its address.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, TermLink>
     */
    public function termLinks(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        app(RenderRecord::class)->tag(...array_map(PageTags::term(...), $ids));

        $paths = DB::table('paths')->where('target_type', 'term')->whereIn('target_id', $ids)->where('is_current', true)->pluck('path', 'target_id');
        $links = [];

        foreach (DB::table('terms')->whereIn('id', $ids)->get(['id', 'name', 'taxonomy']) as $term) {
            $path = $paths[$term->id] ?? null;
            $links[(string) $term->id] = new TermLink((string) $term->name, $path === null ? '' : Addresses::toUrl((string) $path), (string) $term->taxonomy);
        }

        return $links;
    }

    /**
     * The first hierarchical taxonomy a type uses, whose first term is its items' kicker.
     */
    public function kickerTaxonomy(string $type): ?string
    {
        return PrimaryCategory::taxonomyFor($type);
    }

    /**
     * The flat taxonomies a type uses, whose terms are its items' tags.
     *
     * @return list<string>
     */
    public function flatTaxonomies(string $type): array
    {
        return array_values(array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, array_filter($this->taxonomies->forType($type), fn (Taxonomy $taxonomy): bool => ! $taxonomy->hierarchical)));
    }

    /**
     * The published, live items of these types. One type is compared with
     * `=`, as it always was, so a listing of one type keeps the plans its
     * indexes were built for; several with IN; none matches nothing.
     *
     * @param  list<string>  $types
     */
    public static function archive(array $types): Builder
    {
        $items = DB::table('contents');

        if (count($types) === 1) {
            $items->where('contents.type', $types[0]);
        } else {
            $items->whereIn('contents.type', $types);
        }

        return $items
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->where('contents.published_at', '<=', now())
            // Private items are read in the admin, never here (I-1700).
            ->where('contents.visibility', '<>', 'private');
    }

    /**
     * Whether a term of this taxonomy is counted from the items its archive
     * lists rather than from term_counts (I-3360): a taxonomy that isn't
     * translated files every language's items under one term, and
     * term_counts counts them all, while its archive lists the default's.
     */
    public static function countsInDefault(string $taxonomy): bool
    {
        $languages = app(SiteLanguages::class);

        return $languages->isMultilingual() && ! $languages->translatesTaxonomy($taxonomy);
    }

    /**
     * Each term's live public items of these types in the default language,
     * as its archive lists them, by term ID; one statement.
     *
     * @param  list<string>  $termIds
     * @param  list<string>  $types
     *
     * @return array<string, int>
     */
    public static function listedInDefault(array $termIds, array $types): array
    {
        if ($termIds === [] || $types === []) {
            return [];
        }

        return self::archive($types)
            ->whereNull('contents.language')
            ->join('content_terms', 'content_terms.content_id', '=', 'contents.id')
            ->where('content_terms.scope', 'published')
            ->whereIn('content_terms.term_id', $termIds)
            ->groupBy('content_terms.term_id')
            ->selectRaw('content_terms.term_id, COUNT(*) AS listed')
            ->pluck('listed', 'term_id')
            ->map(fn (mixed $listed): int => (int) $listed)
            ->all();
    }

    /**
     * These items narrowed to one language's (D1): the default's are those
     * with no language, another's those with its tag. While the site has one
     * language every item is the default's, since a language is removed only
     * once it holds none, so the default's list is left as it was and keeps
     * the plans its indexes were built for.
     */
    public static function inLanguage(Builder $items, ?string $language): Builder
    {
        if ($language !== null) {
            return $items->where('contents.language', $language);
        }

        return app(SiteLanguages::class)->isMultilingual() ? $items->whereNull('contents.language') : $items;
    }

    /**
     * A path after a language's prefix; the default language's path as it is.
     */
    public static function prefixed(?string $language, string $path): string
    {
        if ($language === null) {
            return $path;
        }

        $prefix = app(SiteLanguages::class)->prefix($language);

        return $path === '' ? $prefix : $prefix.'/'.$path;
    }

    /**
     * How many of a listing's items there are: the stored count while the
     * site has one language, which holds every item, else counted (D1).
     *
     * @param  list<string>  $types
     */
    private function count(Builder $items, array $types, ?string $language, int $authorId = 0): int
    {
        if ($language === null && ! app(SiteLanguages::class)->isMultilingual()) {
            return ContentCounts::published($types, $authorId);
        }

        return self::countUpTo($items, PathResolver::LAST_PAGE * $this->perPage());
    }

    /**
     * One page of these items, newest first, by offset; never past page 1,000, which PathResolver refuses.
     *
     * @return list<ListingItem>
     */
    private function page(Builder $items, int $page): array
    {
        $perPage = $this->perPage();

        return $this->items($items->orderByDesc('contents.published_at')->orderByDesc('contents.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->pluck('contents.id')->map(fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * Each item's kicker, its primary category (I-869): the chosen one while it is among its published terms in its type's first hierarchical taxonomy, else the first of those.
     *
     * @param  array<string, object>  $rows
     * @param  array<string, array<string, mixed>>  $fields  each item's published fields, by its ID
     *
     * @return array<string, TermLink>
     */
    private function kickers(array $rows, array $fields): array
    {
        $taxonomyOf = [];

        foreach ($rows as $id => $row) {
            $taxonomyOf[$id] = $this->kickerTaxonomy((string) $row->type);
        }

        $taxonomies = array_values(array_unique(array_filter($taxonomyOf)));

        if ($taxonomies === []) {
            return [];
        }

        $sections = [];

        foreach (DB::table('content_terms')
            ->join('terms', 'terms.id', '=', 'content_terms.term_id')
            ->whereIn('content_terms.content_id', array_keys($rows))
            ->where('content_terms.scope', 'published')
            ->whereIn('terms.taxonomy', $taxonomies)
            ->orderBy('content_terms.position')
            ->get(['content_terms.content_id', 'terms.id', 'terms.taxonomy']) as $term) {
            if ($term->taxonomy === $taxonomyOf[$term->content_id]) {
                $sections[(string) $term->content_id][] = (string) $term->id;
            }
        }

        $chosen = [];

        foreach ($sections as $id => $ids) {
            $pick = PrimaryCategory::pick($ids, $fields[$id][BuiltInFields::PRIMARY_CATEGORY] ?? null);

            if ($pick !== null) {
                $chosen[(string) $id] = $pick;
            }
        }

        $links = $this->termLinks(array_values(array_unique($chosen)));

        return array_filter(array_map(fn (string $termId): ?TermLink => $links[$termId] ?? null, $chosen));
    }

    /**
     * How many of these items there are, counted no further than $most: a
     * date's range can hold much of a site, and no page past the deepest one
     * served needs counting (1.6h), as search caps its total.
     */
    private static function countUpTo(Builder $items, int $most): int
    {
        return (int) DB::query()->fromSub($items->select('contents.id')->limit($most), 'counted')->count();
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

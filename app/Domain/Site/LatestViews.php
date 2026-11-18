<?php

/**
 * The latest items of the types Settings › Reading ticks (I-3298), at the
 * site's address or the posts page's (I-442): on page 1 the lead, the newest
 * pinned item or else the newest, and up to three more pinned items, then a
 * feed of the rest in one query (study Q §4); later pages hold the feed alone.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\Models\Content;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\ViewModels\LatestView;
use App\Domain\Site\ViewModels\ListingItem;
use App\Domain\Site\ViewModels\ListingView;
use App\Domain\Site\ViewModels\Subject;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;

final readonly class LatestViews
{
    /** Pinned posts shown beside the lead on page 1. */
    private const int FEATURED = 3;

    /**
     * contents_pinned_published's own condition, word for word, bound to
     * nothing, so PostgreSQL can prove the index applies however it plans
     * the statement (1.6h). Its migration writes the same.
     */
    private const string PINNED = "contents.status = 'published' AND contents.deleted_at IS NULL AND contents.visibility <> 'private' AND (contents.fields ->> '".BuiltInFields::STICKY."') = 'true'";

    /** The feature a type supports when its items may be pinned (BuiltInFields::STICKY). */
    private const string STICKY = 'sticky';

    public function __construct(
        private ListingViews $listings,
        private ContentTypeRegistry $types,
        private Breadcrumbs $trail,
    ) {}

    /**
     * One page of the latest items; at the posts page's address, $postsPage
     * gives the feed its subject and its pages' addresses; $language (the
     * stored form) keeps them to one language's items, the default's when null.
     */
    public function latest(int $page, ?Content $postsPage = null, ?string $language = null): LatestView
    {
        $types = ListingTypes::onHome();
        $home = '/'.ListingViews::prefixed($language, '');
        $home = $home === '/' ? '/' : $home.'/';

        if ($types === []) {
            return new LatestView(null, [], new ListingView(null, [], $this->listings->pagination($page, 0, $home)));
        }

        // The latest items are the lists of the types Reading ticks, whichever they are now (I-263).
        app(RenderRecord::class)->tag(...array_map(PageTags::listOf(...), $types));

        // Worked out on every page, so the feed leaves out the same items on each.
        $pinned = $this->pinned($types, $language);
        $lead = $pinned[0] ?? ListingViews::inLanguage(ListingViews::archive($types), $language)->orderByDesc('published_at')->orderByDesc('id')->value('id');
        $featured = array_slice($pinned, 1, self::FEATURED);
        $shown = array_values(array_filter([$lead === null ? null : (string) $lead, ...$featured]));

        $perPage = $this->listings->perPage();
        $feed = ListingViews::inLanguage(ListingViews::archive($types), $language)
            ->whereNotIn('contents.id', $shown)
            ->orderByDesc('contents.published_at')->orderByDesc('contents.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->pluck('contents.id')->map(fn (mixed $id): string => (string) $id)->all();

        $count = max(0, $this->count($types, $language) - count($shown));
        $subject = $postsPage === null ? null : new Subject('posts', $this->publishedTitle($postsPage));
        $base = $postsPage === null ? $home : (Addresses::relativeUrl($postsPage) ?? $home);

        // The page's cards in one list, so their rows, kickers, authors'
        // addresses and images are read once for the whole page (issue 30).
        $items = $this->listings->itemsById(array_values(array_unique([...($page === 1 ? $shown : []), ...$feed])));

        return new LatestView(
            $page === 1 && $lead !== null ? ($items[(string) $lead] ?? null) : null,
            $page === 1 ? self::pick($items, $featured) : [],
            new ListingView($subject, self::pick($items, $feed), $this->listings->pagination($page, $count, $base)),
            breadcrumbs: ! $this->trail->wanted() ? null : ($subject === null ? [$this->trail->home()] : $this->trail->to($subject->title, $base)),
        );
    }

    /**
     * The pinned items of the types that may pin them, newest first: as many as page 1 shows.
     *
     * @param  list<string>  $types
     *
     * @return list<string>
     */
    private function pinned(array $types, ?string $language): array
    {
        $pinnable = array_values(array_filter($types, fn (string $type): bool => $this->types->get($type)->supports(self::STICKY)));

        if ($pinnable === []) {
            return [];
        }

        return ListingViews::inLanguage(ListingViews::archive($pinnable), $language)
            ->whereRaw(self::PINNED)
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(1 + self::FEATURED)
            ->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * How many items of these types are live in this language: the stored
     * count while the site has one language, which holds every item, else
     * counted no further than the deepest page served (D1).
     *
     * @param  list<string>  $types
     */
    private function count(array $types, ?string $language): int
    {
        if ($language === null && ! app(SiteLanguages::class)->isMultilingual()) {
            return ContentCounts::published($types);
        }

        $items = ListingViews::inLanguage(ListingViews::archive($types), $language)->select('contents.id')->limit(PathResolver::LAST_PAGE * $this->listings->perPage());

        return (int) DB::query()->fromSub($items, 'counted')->count();
    }

    private function publishedTitle(Content $content): string
    {
        app(RenderRecord::class)->tag(PageTags::content((string) $content->id));

        return (string) (DB::table('content_revisions')->where('id', $content->published_revision_id)->value('title') ?? $content->title);
    }

    /**
     * These items, in this order, from those read for the page; one that
     * isn't published and live is left out, as items() leaves it out.
     *
     * @param  array<string, ListingItem>  $items
     * @param  list<string>  $ids
     *
     * @return list<ListingItem>
     */
    private static function pick(array $items, array $ids): array
    {
        $picked = [];

        foreach ($ids as $id) {
            if (isset($items[$id])) {
                $picked[] = $items[$id];
            }
        }

        return $picked;
    }
}

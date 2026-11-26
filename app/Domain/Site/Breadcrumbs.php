<?php

/**
 * The breadcrumb trail as a theme draws it (I-295): home, then the page's
 * parents or its place among the site's categories, then the page. Built only for a theme
 * that says it draws one, so a page on any other reads nothing for it; the
 * BreadcrumbList is made from the same steps.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\ViewModels\Crumb;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;

final readonly class Breadcrumbs
{
    public function __construct(private ActiveTheme $active) {}

    /** Whether the active theme draws a trail. */
    public function wanted(): bool
    {
        return $this->active->get()?->manifest?->breadcrumbs() ?? false;
    }

    /** The trail's first step: the home of the page's language, the site's address for the default (I-3360). */
    public function home(): Crumb
    {
        $languages = app(SiteLanguages::class);
        $prefix = $languages->prefix(app(PageLanguage::class)->tag());

        return new Crumb(__('Home'), $prefix === '' ? '/' : '/'.$prefix.'/');
    }

    /**
     * The trail to an item: home, its parents that readers can see, then
     * the item (I-3363); for an item with none, home, its primary
     * category's parents and the category, then the item. A parent at the
     * home page's address is left out: Home stands for it. An item of a type
     * that doesn't nest and has an archive page of its own goes through that
     * page instead: home, the archive, the item.
     *
     * @param  list<Crumb>  $ancestors  the item's live parents, the top first
     * @param  string|null  $type  the item's type key
     *
     * @return list<Crumb>
     */
    public function toItem(string $title, string $url, ?string $categoryId, array $ancestors = [], ?string $type = null): array
    {
        if ($ancestors !== []) {
            $home = $this->home();

            return [$home, ...array_values(array_filter($ancestors, fn (Crumb $crumb): bool => $crumb->url !== $home->url)), new Crumb($title, $url)];
        }

        $archive = $type === null ? null : $this->archive($type);

        if ($archive !== null) {
            return [$this->home(), $archive, new Crumb($title, $url)];
        }

        return [$this->home(), ...($categoryId === null ? [] : $this->terms($categoryId)), new Crumb($title, $url)];
    }

    /**
     * The step for a type's own archive page, in the page's language: its
     * plural name at its address; null for a type that nests, one with no
     * archive page of its own, and the first public type with an archive,
     * whose list is the latest posts, so its trail keeps its category.
     */
    private function archive(string $type): ?Crumb
    {
        $types = app(ContentTypeRegistry::class);
        $path = $types->has($type) && ! $types->get($type)->hierarchical ? TypeArchives::path($type) : null;

        if ($path === null || ! in_array($type, TypeArchives::types(), true)) {
            return null;
        }

        return new Crumb($types->get($type)->plural, Addresses::toUrl(ListingViews::prefixed(app(PageLanguage::class)->stored(), $path)));
    }

    /**
     * The trail to a term's archive: home, its parents, the term.
     *
     * @return list<Crumb>
     */
    public function toTerm(string $termId): array
    {
        return [$this->home(), ...$this->terms($termId)];
    }

    /**
     * The trail to a page that isn't an item: an author's archive, a date's,
     * the posts page.
     *
     * @return list<Crumb>
     */
    public function to(string $name, string $url): array
    {
        return [$this->home(), new Crumb($name, $url)];
    }

    /**
     * The term and its parents, the topmost first, each at its address, in
     * one statement; each records its tag, since its name is shown.
     *
     * @return list<Crumb>
     */
    private function terms(string $termId): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE chain AS (
                SELECT id, parent_id, name, 0 AS depth FROM terms WHERE id = ?
                UNION ALL
                SELECT t.id, t.parent_id, t.name, chain.depth + 1 FROM terms t JOIN chain ON t.id = chain.parent_id
            )
            SELECT chain.id, chain.name, paths.path FROM chain
            LEFT JOIN paths ON paths.target_type = 'term' AND paths.target_id = chain.id::text AND paths.is_current
            ORDER BY chain.depth DESC
            SQL, [$termId]);

        app(RenderRecord::class)->tag(...array_map(fn (object $row): string => PageTags::term((string) $row->id), $rows));

        return array_map(fn (object $row): Crumb => new Crumb((string) $row->name, $row->path === null ? '' : Addresses::toUrl((string) $row->path)), $rows);
    }
}

<?php

/**
 * Every shown language version of the page being drawn that a reader can
 * open, this page included, in the site's language order (I-3360): an
 * item's or a term's group, each language's home on the home page, and on a
 * type's archive the languages that have its items. Empty when there is
 * only the page itself, and on every other page. Read once a page, so the
 * view data, the head and the API agree; scoped, so each request starts
 * afresh.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Api\Live;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\ViewModels\Alternate;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\LatestView;
use App\Domain\Site\ViewModels\ListingView;
use App\Domain\Themes\ComposerRegistry;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

#[Scoped]
final class Alternates
{
    /** The page whose versions are kept: its view model, compared by identity, and its address. */
    private ?object $model = null;

    private string $path = '';

    /** @var list<Alternate>|null */
    private ?array $alternates = null;

    /** @var list<string> The page's versions, by tag, that search engines are told not to list. */
    private array $unlisted = [];

    public function __construct(private readonly SiteLanguages $languages, private readonly PageLanguage $page) {}

    /**
     * The page's versions: resolved once for its view model and address,
     * kept while that page is drawn.
     *
     * @return list<Alternate>
     */
    public function for(DocumentPage $page): array
    {
        $path = request()->getPathInfo();

        if ($this->alternates !== null && $page->model === $this->model && $path === $this->path) {
            return $this->alternates;
        }

        $this->model = $page->model;
        $this->path = $path;
        $this->unlisted = [];

        return $this->alternates = $this->resolve($page);
    }

    /**
     * The page's versions for search engines, in its head's hreflang: as
     * for(), less the versions search engines are told not to list, which
     * the switcher still offers readers; [] when only one is left, or when
     * the page itself is one of those, such as a home or archive in a
     * language with nothing published.
     *
     * @return list<Alternate>
     */
    public function listed(DocumentPage $page): array
    {
        $versions = $this->for($page);

        // A page left out of its own set would list pages that never list it back.
        if (in_array($this->page->tag(), $this->unlisted, true)) {
            return [];
        }

        $listed = array_values(array_filter($versions, fn (Alternate $alternate): bool => ! in_array($alternate->tag, $this->unlisted, true)));

        return count($listed) < 2 ? [] : $listed;
    }

    /**
     * The versions of the page the theme is drawing now, as ComposerRegistry
     * knows it; [] outside a page.
     *
     * @return list<Alternate>
     */
    public function current(): array
    {
        $composers = app(ComposerRegistry::class);

        return $this->for(new DocumentPage($composers->intent(), $composers->model()));
    }

    /**
     * An item's live, public versions in shown languages, each at its
     * current address, the item itself among them; [] when it is alone or
     * isn't one a reader can open, each saying whether search engines are
     * told not to list it. One statement.
     *
     * @return list<array{id: string, language: string, path: string, type: string, noindex: bool}>
     */
    public function ofItem(string $id): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.id, c.language, c.type, c.translation_group, p.path, (c.fields -> ?) IS NOT DISTINCT FROM 'true'::jsonb AS noindex
            FROM contents c
            JOIN paths p ON p.target_type = 'content' AND p.target_id = c.id::text AND p.is_current
            WHERE c.translation_group = (SELECT translation_group FROM contents WHERE id = ?)
                AND c.status = 'published' AND c.deleted_at IS NULL AND c.visibility = 'public' AND c.published_at <= ?
            SQL, [BuiltInFields::NOINDEX, $id, now()]);

        return $this->members($rows, $id, 'content');
    }

    /**
     * A term's versions in shown languages that have an address, the term
     * itself among them; [] when it is alone. One statement.
     *
     * @return list<array{id: string, language: string, path: string, type: string, noindex: bool}>
     */
    public function ofTerm(string $id): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT t.id, t.language, '' AS type, t.translation_group, p.path, false AS noindex
            FROM terms t
            JOIN paths p ON p.target_type = 'term' AND p.target_id = t.id::text AND p.is_current
            WHERE t.translation_group = (SELECT translation_group FROM terms WHERE id = ?)
            SQL, [$id]);

        return $this->members($rows, $id, 'term');
    }

    /**
     * @return list<Alternate>
     */
    private function resolve(DocumentPage $page): array
    {
        if (! $this->languages->isMultilingual()) {
            return [];
        }

        $model = $page->model;
        $kind = $page->intent?->kind;
        $listing = $model instanceof LatestView ? $model->feed : ($model instanceof ListingView ? $model : null);

        // A later page of a listing lists other items in each language (I-3360).
        if ($listing !== null && $listing->pagination->current > 1) {
            return [];
        }

        return match (true) {
            $page->intent->isFront ?? false => $this->homes(),
            $kind === 'single' && $model instanceof ArticleView => $this->versions($this->ofItem($model->id)),
            $kind === 'latest' => $this->versions(($id = $this->targetHere('content')) === null ? [] : $this->ofItem($id)),
            $kind === 'taxonomy' => $this->versions(($id = $this->targetHere('term')) === null ? [] : $this->ofTerm($id)),
            $kind === 'archive' && $page->intent->type !== null => $this->archives((string) $page->intent->type),
            default => [],
        };
    }

    /**
     * Each shown language's home that has something published, the page's
     * own language always among them. Tagged with every public type's list,
     * so a cached home page follows a language's first and last items.
     *
     * @return list<Alternate>
     */
    private function homes(): array
    {
        $record = app(RenderRecord::class);

        foreach (Live::types() as $type) {
            $record->tag(PageTags::listOf($type));
        }

        $entries = [];

        foreach ($this->withItems(null) as $tag) {
            $prefix = $this->languages->prefix($tag);
            $entries[$tag] = $prefix === '' ? '/' : '/'.$prefix.'/';
        }

        return $this->alternatesOf($entries);
    }

    /**
     * The type's archive page in each shown language that has a live item of
     * the type, the page's own language always among them.
     *
     * @return list<Alternate>
     */
    private function archives(string $type): array
    {
        $path = TypeArchives::path($type);

        if ($path === null) {
            return [];
        }

        app(RenderRecord::class)->tag(PageTags::listOf($type));
        $entries = [];

        foreach ($this->withItems($type) as $tag) {
            $prefix = $this->languages->prefix($tag);
            $entries[$tag] = Addresses::toUrl($prefix === '' ? $path : $prefix.'/'.$path, $type);
        }

        return $this->alternatesOf($entries);
    }

    /**
     * The shown languages, in the site's order, that have a live, public
     * item a reader can open (of this type, when one is named, else of any
     * public type): one indexed probe a language, the page's own language
     * always among them, kept from search engines when it has none.
     *
     * @return list<string>
     */
    private function withItems(?string $type): array
    {
        $found = [];
        $current = $this->page->tag();

        foreach ($this->languages->shown() as $tag) {
            $stored = $this->languages->stored($tag);
            $query = DB::table('contents')
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->where('visibility', 'public')
                ->where('published_at', '<=', now())
                ->when($type !== null, fn ($query) => $query->where('type', $type), fn ($query) => $query->whereIn('type', Live::types()));

            $query = $stored === null ? $query->whereNull('language') : $query->where('language', $stored);

            if ($query->exists()) {
                $found[] = $tag;
            } elseif ($tag === $current) {
                // The switcher still offers its home; search engines aren't told of it, as the others never list it.
                $found[] = $tag;
                $this->unlisted[] = $tag;
            }
        }

        return $found;
    }

    /**
     * The members of a group as rows, the ones a reader can open, ordered
     * by the site's languages, with each one's tags recorded; [] when the
     * named one isn't among them, or is alone.
     *
     * @param  array<int, object>  $rows
     *
     * @return list<array{id: string, language: string, path: string, type: string, noindex: bool}>
     */
    private function members(array $rows, string $id, string $kind): array
    {
        $record = app(RenderRecord::class);
        $byLanguage = [];
        $group = null;

        foreach ($rows as $row) {
            $group = (string) $row->translation_group;

            if (! $this->languages->isShown($row->language === null ? null : (string) $row->language)) {
                continue;
            }

            $tag = $this->languages->of($row->language === null ? null : (string) $row->language);
            $byLanguage[$tag] = ['id' => (string) $row->id, 'language' => $tag, 'path' => (string) $row->path, 'type' => (string) $row->type, 'noindex' => (bool) $row->noindex];
        }

        if ($group !== null) {
            $record->tag(PageTags::translations($group));
        }

        $ordered = [];

        foreach ($this->languages->all() as $tag) {
            if (isset($byLanguage[$tag])) {
                $ordered[] = $byLanguage[$tag];
                $record->tag($kind === 'content' ? PageTags::content($byLanguage[$tag]['id']) : PageTags::term($byLanguage[$tag]['id']));
            }
        }

        if (count($ordered) < 2 || ! in_array($id, array_column($ordered, 'id'), true)) {
            return [];
        }

        return $ordered;
    }

    /**
     * Rows of a group as the page's alternates, this page's marked current.
     *
     * @param  list<array{id: string, language: string, path: string, type: string, noindex: bool}>  $members
     *
     * @return list<Alternate>
     */
    private function versions(array $members): array
    {
        $entries = [];

        foreach ($members as $member) {
            if ($member['noindex']) {
                $this->unlisted[] = $member['language'];
            }

            $entries[$member['language']] = Addresses::toUrl($member['path'], $member['type'] === '' ? null : $member['type']);
        }

        return $this->alternatesOf($entries);
    }

    /**
     * Site-relative addresses by language tag as alternates, [] for one.
     *
     * @param  array<string, string>  $entries
     *
     * @return list<Alternate>
     */
    private function alternatesOf(array $entries): array
    {
        if (count($entries) < 2) {
            return [];
        }

        $root = rtrim(url('/'), '/');
        $current = $this->page->tag();
        $default = $this->languages->defaultTag();
        $alternates = [];

        foreach ($entries as $tag => $url) {
            $alternates[] = new Alternate($tag, $this->languages->native($tag), $root.PublicRequestPath::encode($url), $tag === $current, $tag === $default);
        }

        return $alternates;
    }

    /**
     * The content item or term at the request's address, by its current
     * path, or null.
     */
    private function targetHere(string $type): ?string
    {
        $id = DB::table('paths')
            ->where('path', PublicRequestPath::normalise(request()->getPathInfo()))
            ->where('target_type', $type)
            ->where('is_current', true)
            ->value('target_id');

        return $id === null ? null : (string) $id;
    }
}

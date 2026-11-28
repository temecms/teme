<?php

/**
 * The site's search: the engine the administrator chose for the search slot
 * (docs/guides/developers/plugins/search-engine.md), or core's PostgreSQL search
 * when there is none. An engine that fails or runs over its budget gives way to
 * core's search silently, and its fault is recorded once. Either way the page
 * cache is told which lists the search draws, so a publish purges them.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Plugins\Seo\SeoPlugin;
use App\Domain\Plugins\Seo\SeoPlugins;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Search\HasSearchEngine;
use Teme\Contracts\Search\SearchHit;
use Teme\Contracts\Search\SearchHits;
use Teme\Contracts\Search\SearchRequest;
use Teme\Contracts\Search\SiteSearch;
use Throwable;

final readonly class RoutedSiteSearch implements SiteSearch
{
    /** The socket's name in a recorded fault. */
    public const string SOCKET = 'search';

    /** The time an engine has to answer one search, in milliseconds. */
    public const int CALL_BUDGET_MS = 300;

    public function __construct(
        private PostgresSiteSearch $core,
        private SlotProviders $providers,
        private SeoPlugins $plugins,
    ) {}

    public function search(SearchRequest $request): SearchHits
    {
        $engine = $this->engine();

        if ($engine === null) {
            return $this->core->search($request);
        }

        // The lists the engine's answer draws are the ones core would draw, so a publish purges the same cached pages.
        if (trim($request->query) !== '') {
            // Tagged with the engine's plugin too, so switching engines purges the pages it answered (ChooseSlotProvider).
            app(RenderRecord::class)->tag('plugin:'.$engine['plugin']->code, ...array_map(PageTags::listOf(...), $this->core->searchable($request->counts ? null : $request->types)));
        }

        // A failure or a late answer is recorded by SeoPlugins::ask, once; the search then falls back to core's.
        $hits = $this->plugins->ask($engine['plugin'], self::SOCKET, SiteSearch::class, self::CALL_BUDGET_MS, fn (): SearchHits => $engine['search']->search($request));

        // An engine's silence or failure is core's to answer, not the visitor's.
        return $hits instanceof SearchHits ? $this->shown($hits, $request) : $this->core->search($request);
    }

    /**
     * The engine's hits the visitor may see: published, public, of a searched type and in the language searched.
     * Each snippet is escaped, and only a mark survives (the snippet is printed raw on the cached page).
     */
    private function shown(SearchHits $hits, SearchRequest $request): SearchHits
    {
        $types = $this->core->searchable($request->types);
        $ids = array_map(fn (SearchHit $hit): string => $hit->contentId, $hits->hits);

        if ($ids === [] || $types === []) {
            return new SearchHits([], 0, false);
        }

        $language = $request->language ?? app(SiteLanguages::class)->defaultTag();
        $defaultTag = app(SiteLanguages::class)->defaultTag();

        // A null language is the site's default, as core's search reads it.
        $allowed = DB::table('contents')
            ->whereIn('id', $ids)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->where('visibility', 'public')
            ->whereIn('type', $types)
            ->where(fn ($query) => $language === $defaultTag ? $query->whereNull('language')->orWhere('language', $language) : $query->where('language', $language))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->flip()
            ->all();

        $kept = [];

        foreach ($hits->hits as $hit) {
            if (isset($allowed[$hit->contentId])) {
                $kept[] = new SearchHit($hit->contentId, self::escaped($hit->snippetHtml));
            }
        }

        return new SearchHits($kept, max(0, $hits->total - (count($hits->hits) - count($kept))), false);
    }

    /**
     * An engine's snippet, escaped, with only the marks that highlight a match left as they are.
     */
    private static function escaped(string $snippet): string
    {
        return str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * The chosen engine's plugin and its search, or null for core's.
     *
     * @return array{plugin: SeoPlugin, search: SiteSearch}|null
     */
    private function engine(): ?array
    {
        $chosen = $this->providers->chosenFor(PluginSlots::SEARCH);

        if ($chosen === null) {
            return null;
        }

        foreach ($this->plugins->all() as $plugin) {
            // Only a plugin granted search.read is handed the visitors' words.
            if ($plugin->name !== $chosen || ! $plugin->running instanceof HasSearchEngine || ! $plugin->grants(Capability::SearchRead->value)) {
                continue;
            }

            // An engine that can't be handed over is a fault, and core answers.
            try {
                return ['plugin' => $plugin, 'search' => $plugin->running->engine()];
            } catch (Throwable $failure) {
                $this->plugins->fault($plugin, self::SOCKET, HasSearchEngine::class, $failure);

                return null;
            }
        }

        return null;
    }
}

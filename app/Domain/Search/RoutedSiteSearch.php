<?php

/**
 * The site's search: the engine the administrator chose for the search slot
 * (docs/guides/developers/plugins/search-engine.md), or core's PostgreSQL search
 * when there is none. An engine that fails or runs over its budget gives way to
 * core's search silently, and its fault is recorded once. Either way the page
 * cache is told which lists the search draws, so a publish purges them.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Plugins\Seo\SeoPlugin;
use App\Domain\Plugins\Seo\SeoPlugins;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use Teme\Contracts\Search\HasSearchEngine;
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
            app(RenderRecord::class)->tag(...array_map(PageTags::listOf(...), $this->core->searchable($request->counts ? null : $request->types)));
        }

        // A failure or a late answer is recorded by SeoPlugins::ask, once; the search then falls back to core's.
        $hits = $this->plugins->ask($engine['plugin'], self::SOCKET, SiteSearch::class, self::CALL_BUDGET_MS, fn (): SearchHits => $engine['search']->search($request));

        // An engine's silence or failure is core's to answer, not the visitor's.
        return $hits instanceof SearchHits ? $hits : $this->core->search($request);
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
            if ($plugin->name !== $chosen || ! $plugin->running instanceof HasSearchEngine) {
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

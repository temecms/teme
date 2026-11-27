<?php

/**
 * Keeps the chosen search engine's index in step with the site's published
 * items (docs/guides/developers/plugins/search-engine.md): sends an item when
 * it is published, drops one when it is not, and sends every published item
 * on a reindex. An engine that fails is recorded once and left behind until
 * the next change, which search can tolerate.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Contents\Models\Content;
use App\Domain\Plugins\Seo\SeoPlugin;
use App\Domain\Plugins\Seo\SeoPlugins;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Search\HasSearchEngine;
use Teme\Contracts\Search\SearchEntry;
use Teme\Contracts\Search\SearchIndex;
use Throwable;

final readonly class SearchEngineSync
{
    public function __construct(
        private SeoPlugins $plugins,
        private SlotProviders $providers,
    ) {}

    /**
     * Sends an item to the chosen engine when it is published, or drops it when it is not.
     */
    public function sync(string $contentId): void
    {
        $engine = $this->engine();

        if ($engine === null) {
            return;
        }

        $entry = $this->entry($contentId);

        $this->send($engine, fn (SearchIndex $index): null => $entry === null ? $index->remove($contentId) : $index->index($entry));
    }

    /**
     * Sends every published, public item to the chosen engine.
     */
    public function reindex(): void
    {
        $engine = $this->engine();

        if ($engine === null) {
            return;
        }

        Content::query()->where('status', 'published')->whereNull('deleted_at')->whereNotNull('published_revision_id')->where('visibility', 'public')->orderBy('id')->chunkById(200, function ($items) use ($engine): void {
            foreach ($items as $item) {
                $entry = $this->entry((string) $item->id);

                if ($entry !== null) {
                    $this->send($engine, fn (SearchIndex $index): null => $index->index($entry));
                }
            }
        });
    }

    /**
     * The entry for a published, public item, or null when it isn't one.
     */
    private function entry(string $contentId): ?SearchEntry
    {
        $item = Content::query()->where('status', 'published')->whereNull('deleted_at')->whereNotNull('published_revision_id')->where('visibility', 'public')->find($contentId);

        if ($item === null) {
            return null;
        }

        $published = DB::table('content_revisions')->where('id', $item->published_revision_id)->first(['title', 'plain_text']);

        return new SearchEntry(
            contentId: (string) $item->id,
            type: (string) $item->type,
            language: $item->language === null ? null : (string) $item->language,
            title: (string) ($published->title ?? ''),
            text: (string) ($published->plain_text ?? ''),
            publishedAt: $item->published_at === null ? null : Carbon::parse((string) $item->published_at)->toIso8601String(),
        );
    }

    /**
     * Runs one call on the engine's index, recording a failure once.
     *
     * @param  array{plugin: SeoPlugin, index: SearchIndex}  $engine
     * @param  callable(SearchIndex): mixed  $call
     */
    private function send(array $engine, callable $call): void
    {
        try {
            $call($engine['index']);
        } catch (Throwable $failure) {
            $this->plugins->fault($engine['plugin'], RoutedSiteSearch::SOCKET, SearchIndex::class, $failure);
        }
    }

    /**
     * The chosen engine's plugin and its index, or null for core's search.
     *
     * @return array{plugin: SeoPlugin, index: SearchIndex}|null
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

            try {
                return ['plugin' => $plugin, 'index' => $plugin->running->indexer()];
            } catch (Throwable $failure) {
                $this->plugins->fault($plugin, RoutedSiteSearch::SOCKET, HasSearchEngine::class, $failure);

                return null;
            }
        }

        return null;
    }
}

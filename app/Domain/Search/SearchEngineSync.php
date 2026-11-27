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

use App\Domain\Plugins\Seo\SeoPlugin;
use App\Domain\Plugins\Seo\SeoPlugins;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Search\HasSearchEngine;
use Teme\Contracts\Search\SearchEntry;
use Teme\Contracts\Search\SearchIndex;
use Throwable;

final readonly class SearchEngineSync
{
    /** How many items one reindex job sends. */
    public const int CHUNK = 200;

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
     * Sends one chunk of the published, public items, from after the given id
     * (the first when null), to the chosen engine. Each item comes with its
     * published revision from the chunk's own query, so a chunk costs one
     * query, not one for each item. Returns the last id of a full chunk, which
     * the next chunk follows, or null when this was the last.
     */
    public function reindexChunk(?string $after, int $size = self::CHUNK): ?string
    {
        $engine = $this->engine();

        if ($engine === null) {
            return null;
        }

        $rows = $this->published()
            ->when($after !== null, fn (Builder $query): Builder => $query->where('contents.id', '>', $after))
            ->orderBy('contents.id')
            ->limit($size)
            ->get($this->columns());

        foreach ($rows as $row) {
            $entry = $this->entryFrom($row);
            $this->send($engine, fn (SearchIndex $index): null => $index->index($entry));
        }

        return $rows->count() === $size ? (string) $rows->last()->id : null;
    }

    /**
     * The entry for a published, public item, or null when it isn't one.
     */
    private function entry(string $contentId): ?SearchEntry
    {
        $row = $this->published()->where('contents.id', $contentId)->first($this->columns());

        return $row === null ? null : $this->entryFrom($row);
    }

    /**
     * The published, public items, each with its published revision's title and text.
     */
    private function published(): Builder
    {
        return DB::table('contents')
            ->join('content_revisions', 'content_revisions.id', '=', 'contents.published_revision_id')
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->where('contents.visibility', 'public');
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return ['contents.id', 'contents.type', 'contents.language', 'contents.published_at', 'content_revisions.title', 'content_revisions.plain_text'];
    }

    /**
     * The search entry for one row of published().
     */
    private function entryFrom(object $row): SearchEntry
    {
        return new SearchEntry(
            contentId: (string) $row->id,
            type: (string) $row->type,
            language: $row->language === null ? null : (string) $row->language,
            title: (string) $row->title,
            text: (string) $row->plain_text,
            publishedAt: $row->published_at === null ? null : Carbon::parse((string) $row->published_at)->toIso8601String(),
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

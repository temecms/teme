<?php

/**
 * Plugins' sitemaps in the site's sitemap index, after core's
 * (docs/guides/developers/plugins/seo.md): each one an enabled plugin
 * granted sitemaps declares, named `plugin-{code}-{id}` so it never meets
 * one of core's names, cached and tagged as
 * core's are and tagged with the plugin too. Each call has 2 seconds; a
 * plugin that fails is recorded as a fault, and the sitemap answers with
 * no entries, or no pages after its first.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cache\PageTags;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Sitemaps\SitemapAddress;
use App\Domain\Sitemaps\SitemapPage;
use App\Domain\Sitemaps\SitemapProvider;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Carbon\CarbonImmutable;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Sitemaps\HasSitemaps;
use Teme\Contracts\Sitemaps\PluginSitemap;
use Teme\Contracts\Sitemaps\SitemapEntry;

final readonly class PluginSitemaps implements SitemapProvider
{
    public const int CALL_BUDGET_MS = 2_000;

    public function __construct(
        private SeoPlugins $plugins,
        private ListedItems $items,
        private ContentTypeRegistry $types,
    ) {}

    public function names(): array
    {
        return array_keys($this->sitemaps());
    }

    public function starts(string $name): array
    {
        [$plugin, $sitemap] = $this->sitemaps()[$name] ?? [null, null];

        if ($plugin === null || $sitemap === null) {
            return [];
        }

        $starts = $this->plugins->ask($plugin, 'sitemaps', PluginSitemap::class, self::CALL_BUDGET_MS, fn (): array => $sitemap->starts());

        return array_values(array_filter(is_array($starts) ? $starts : [], fn (mixed $key): bool => is_string($key) && preg_match(PluginSitemap::KEY, $key) === 1));
    }

    public function page(string $name, ?string $from): SitemapPage
    {
        [$plugin, $sitemap] = $this->sitemaps()[$name] ?? [null, null];

        if ($plugin === null || $sitemap === null) {
            return new SitemapPage([]);
        }

        $entries = $this->plugins->ask($plugin, 'sitemaps', PluginSitemap::class, self::CALL_BUDGET_MS, fn (): array => $sitemap->entries($from));
        $entries = array_values(array_filter(array_slice(is_array($entries) ? $entries : [], 0, PluginSitemap::MOST_ENTRIES), fn (mixed $entry): bool => $entry instanceof SitemapEntry));
        $items = $this->items->addressesOf(array_values(array_filter(array_map(fn (SitemapEntry $entry): ?string => $entry->itemId, $entries))));
        $root = rtrim(url('/'), '/');
        $addresses = [];
        $tags = [];

        foreach ($entries as $entry) {
            if ($entry->path !== null) {
                $addresses[] = new SitemapAddress($root.PublicRequestPath::encode($entry->path), $entry->lastModified === null ? null : CarbonImmutable::instance($entry->lastModified));
            } elseif ($entry->itemId !== null && isset($items[$entry->itemId])) {
                $item = $items[$entry->itemId];
                $addresses[] = new SitemapAddress($root.PublicRequestPath::encode(Addresses::toUrl($item['path'], $item['type'])), CarbonImmutable::parse($item['changed_at']));
                $tags[PageTags::content($entry->itemId)] = true;
            }
        }

        return new SitemapPage($addresses, array_keys($tags));
    }

    public function tags(string $name): array
    {
        [$plugin] = $this->sitemaps()[$name] ?? [null];

        // Any item's change may change what it lists, as a type's change does core's.
        return $plugin === null ? [] : ['plugin:'.$plugin->code, ...array_map(fn (ContentType $type): string => PageTags::listOf($type->key), array_values(array_filter($this->types->all(), fn (ContentType $type): bool => $type->public)))];
    }

    /**
     * Each plugin sitemap that answers now, by its name in the index.
     *
     * @return array<string, array{0: SeoPlugin, 1: PluginSitemap}>
     */
    private function sitemaps(): array
    {
        $found = [];

        foreach ($this->plugins->all() as $plugin) {
            $running = $plugin->running;

            if (! $plugin->grants(Capability::Sitemaps->value) || ! $running instanceof HasSitemaps) {
                continue;
            }

            $given = $this->plugins->ask($plugin, 'sitemaps', HasSitemaps::class, self::CALL_BUDGET_MS, fn (): array => $running->sitemaps());

            foreach (SeoDeclarations::sitemaps($plugin->manifest) as $declared) {
                $sitemap = is_array($given) ? ($given[$declared['id']] ?? null) : null;

                if ($sitemap instanceof PluginSitemap) {
                    $found['plugin-'.$plugin->code.'-'.$declared['id']] = [$plugin, $sitemap];
                }
            }
        }

        return $found;
    }
}

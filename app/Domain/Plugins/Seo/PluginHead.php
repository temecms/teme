<?php

/**
 * Plugins' head tags and structured data on public pages
 * (docs/guides/developers/plugins/seo.md), at the plugin's precedence: each
 * enabled plugin granted pages.head is asked within 100 ms for its `<meta>`
 * tags and its graph nodes. Its robots value only ever tightens core's
 * (PluginRobots). A node is kept only with an @id on this site
 * and only schema.org terms on the graph's list or the plugin's own; core's
 * graph rules then merge and tidy it. A plugin that fails is recorded as a
 * fault, and the page draws without what it would have given.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Cache\RenderRecord;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Document\MetaTag;
use App\Domain\Site\Document\Precedence;
use App\Domain\Site\Head\PageHead;
use App\Domain\StructuredData\Vocabulary;
use Teme\Contracts\Head\HasHeadMeta;
use Teme\Contracts\Head\HeadMeta;
use Teme\Contracts\Head\HeadPage;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\StructuredData\GraphNode;
use Teme\Contracts\StructuredData\HasStructuredData;
use Throwable;

final readonly class PluginHead implements HeadContributor
{
    public const int CALL_BUDGET_MS = 100;

    /** The most tags one plugin may add to a page. */
    public const int MOST_TAGS = 20;

    /** The most nodes one plugin may add to a page. */
    public const int MOST_NODES = 50;

    public function __construct(
        private SeoPlugins $plugins,
        private PageHead $heads,
        private HeadPages $pages,
        private Vocabulary $vocabulary,
        private RenderRecord $record,
    ) {}

    public function precedence(): Precedence
    {
        return Precedence::Plugin;
    }

    public function entries(DocumentPage $page): array
    {
        $asked = array_values(array_filter($this->plugins->all(), fn (SeoPlugin $plugin): bool => $plugin->grants(Capability::PagesHead->value)
            && ($plugin->running instanceof HasHeadMeta || $plugin->running instanceof HasStructuredData)));

        if ($asked === [] || $page->intent === null || ! isset(HeadPages::KINDS[$page->intent->kind])) {
            return [];
        }

        $facts = $this->pages->for($page, $this->heads->for($page));

        if ($facts === null) {
            return [];
        }

        $robots = $this->heads->for($page)->robots;
        $entries = [];

        foreach ($asked as $plugin) {
            // Its settings and its own being enabled change what it gives here.
            $this->record->tag('plugin:'.$plugin->code);
            $seen = $facts($plugin);

            if ($plugin->running instanceof HasHeadMeta) {
                array_push($entries, ...$this->meta($plugin, $plugin->running, $seen, $robots));
            }

            if ($plugin->running instanceof HasStructuredData) {
                array_push($entries, ...$this->nodes($plugin, $plugin->running, $seen));
            }
        }

        return $entries;
    }

    /**
     * The plugin's tags, its robots value laid over core's directives
     * rather than replacing them (PluginRobots).
     *
     * @param  list<string>  $robots  core's robots directives for the page
     *
     * @return list<MetaTag>
     */
    private function meta(SeoPlugin $plugin, HasHeadMeta $running, HeadPage $page, array $robots): array
    {
        $given = $this->plugins->ask($plugin, 'head-meta', HasHeadMeta::class, self::CALL_BUDGET_MS, fn (): array => $running->headMeta($page));
        $tags = [];

        foreach (is_array($given) ? array_slice($given, 0, self::MOST_TAGS) : [] as $tag) {
            if (! $tag instanceof HeadMeta) {
                $this->plugins->fault($plugin, 'head-meta', HasHeadMeta::class, new SocketAnswerRefused('A head tag isn’t a HeadMeta.'));

                continue;
            }

            if ($tag->attribute === 'name' && strtolower($tag->key) === 'robots') {
                try {
                    $robotsTag = PluginRobots::tag($robots, $tag->content);

                    if ($robotsTag !== null) {
                        $tags[] = $robotsTag;
                    }
                } catch (SocketAnswerRefused $refused) {
                    $this->plugins->fault($plugin, 'head-meta', HasHeadMeta::class, $refused);
                }

                continue;
            }

            $tags[] = $tag->attribute === 'property' ? MetaTag::property($tag->key, $tag->content) : MetaTag::name($tag->key, $tag->content);
        }

        return $tags;
    }

    /**
     * @return list<JsonLdNode>
     */
    private function nodes(SeoPlugin $plugin, HasStructuredData $running, HeadPage $page): array
    {
        $given = $this->plugins->ask($plugin, 'structured-data', HasStructuredData::class, self::CALL_BUDGET_MS, fn (): array => $running->graphNodes($page));
        $terms = SeoDeclarations::terms($plugin->manifest);
        $vocabulary = clone $this->vocabulary;
        $vocabulary->register($terms['types'], $terms['properties']);
        $nodes = [];

        foreach (is_array($given) ? array_slice($given, 0, self::MOST_NODES) : [] as $node) {
            try {
                if (! $node instanceof GraphNode) {
                    throw new SocketAnswerRefused('A graph node isn’t a GraphNode.');
                }

                $id = (string) $node->properties['@id'];

                if (! str_starts_with($id, $page->home)) {
                    throw new SocketAnswerRefused("The node [{$id}] isn't on this site: its @id starts with the site's address.");
                }

                $unknown = $vocabulary->unknown($node->properties);

                if ($unknown !== []) {
                    throw new SocketAnswerRefused("The node [{$id}] uses terms neither the graph's list nor the plugin's structuredData names: ".implode(', ', $unknown).'.');
                }

                $nodes[] = new JsonLdNode($node->properties);
            } catch (Throwable $refused) {
                $this->plugins->fault($plugin, 'structured-data', HasStructuredData::class, $refused);
            }
        }

        return $nodes;
    }
}

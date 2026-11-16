<?php

declare(strict_types=1);

namespace App\Domain\Site\Links;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\LinkResolver;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\TimeBudget;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Site\Insertions\ContributorTooSlow;
use App\Domain\Site\Insertions\PageInsertions;
use Teme\Contracts\Content\ArticleLinks;
use Teme\Contracts\Content\LinkAttributes;
use Teme\Contracts\Content\OutboundLink;

/**
 * The attributes plugins granted content.links ask for on the links an
 * article draws (docs/plans/plugin-sockets-3.md): ArticleLinks is
 * asked once, with each link once, and what comes back is kept only where
 * Teme's rules allow it. A link to this site is changed only when the
 * plugin names its host. Asks nothing when no plugin listens. The page is
 * tagged with the insertions tag, so a plugin enabled or disabled purges it.
 * Its listeners have the time page insertions have, one budget a page.
 */
final class ArticleLinkAttributes
{
    /** At most this many characters of a link's words are handed over. */
    private const int MOST_TEXT = 200;

    private readonly TimeBudget $budget;

    public function __construct(private readonly HookDispatcher $dispatcher, ColumnClock $clock)
    {
        $this->budget = new TimeBudget(
            PageInsertions::CALL_BUDGET_MS,
            PageInsertions::PAGE_BUDGET_MS,
            $clock->nanoseconds(...),
            fn (string $owner, int $milliseconds, int $budget): ContributorTooSlow => new ContributorTooSlow($owner, $milliseconds, $budget, 'link attributes'),
        );
    }

    /**
     * Returns the attributes to write, by each link's stored href, each with
     * its rel values, whether it opens in a new tab (null: as stored) and
     * its class names; none when no plugin listens or none asks.
     *
     * @param  array<mixed>  $document
     *
     * @return array<string, array{rel: list<string>, newTab: bool|null, classes: list<string>}>
     */
    public function for(array $document, LinkResolver $links): array
    {
        // Tagged before any plugin is asked: one enabled or disabled later purges the page too.
        app(RenderRecord::class)->tag(PageTags::INSERTIONS);

        if (! $this->dispatcher->wants(ArticleLinks::class)) {
            return [];
        }

        $words = [];
        $this->gather($document, $words);
        $siteHost = strtolower((string) parse_url(url('/'), PHP_URL_HOST));
        $outbound = [];
        $hrefs = [];

        foreach ($words as $href => $text) {
            $address = $this->address($href, $links);
            $host = $address === null ? '' : strtolower((string) parse_url($address, PHP_URL_HOST));

            if ($address === null || $host === '') {
                continue;
            }

            $outbound[] = new OutboundLink($address, $host, $host !== $siteHost, mb_substr(trim($text), 0, self::MOST_TEXT));
            $hrefs[] = $href;
        }

        if ($outbound === []) {
            return [];
        }

        $given = $this->dispatcher->transform(new ArticleLinks($outbound), $this->budget);
        $kept = [];

        foreach (is_array($given) ? $given : [] as $place => $attributes) {
            if (! is_int($place) || ! isset($outbound[$place]) || ! $attributes instanceof LinkAttributes) {
                continue;
            }

            $link = $outbound[$place];

            // A link to this site is touched only when the plugin asks for it by its host.
            if (! $link->external && strtolower((string) $attributes->host) !== $link->host) {
                continue;
            }

            $kept[$hrefs[$place]] = [
                'rel' => array_values(array_unique($attributes->rel)),
                'newTab' => $attributes->newTab,
                'classes' => array_values(array_intersect($attributes->classes, $this->themeClasses())),
            ];
        }

        return $kept;
    }

    /**
     * Each link's stored href with the words it is drawn on, in the order
     * they first appear.
     *
     * @param  array<string, string>  $words
     */
    private function gather(mixed $node, array &$words): void
    {
        if (! is_array($node)) {
            return;
        }

        if (($node['type'] ?? null) === 'text' && is_array($node['marks'] ?? null)) {
            foreach ($node['marks'] as $mark) {
                $href = is_array($mark) && ($mark['type'] ?? null) === 'link' ? ($mark['attrs']['href'] ?? null) : null;

                if (is_string($href) && $href !== '') {
                    $words[$href] = ($words[$href] ?? '').(is_string($node['text'] ?? null) ? $node['text'] : '');
                }
            }
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            $this->gather($child, $words);
        }
    }

    /**
     * The absolute address a stored href is drawn at, or null for one drawn
     * as no link, or a place on the same page.
     */
    private function address(string $href, LinkResolver $links): ?string
    {
        $link = DocumentSchema::linkTarget($href);

        $address = match (true) {
            $link !== null => ($target = $links->target($link['kind'], $link['id'])) === null ? null : $target.$link['place'],
            preg_match(DocumentSchema::FRAGMENT, $href) === 1 => null,
            preg_match(DocumentSchema::SITE_PATH, $href) === 1 => $links->path($href),
            DocumentSchema::allowsHref($href) => $href,
            default => null,
        };

        return $address !== null && str_starts_with($address, '/') ? url($address) : $address;
    }

    /**
     * The class names the active theme declares for links: none, while a
     * theme's manifest has no place to declare them, so every class a
     * plugin asks for is left off.
     *
     * @return list<string>
     */
    private function themeClasses(): array
    {
        return [];
    }
}

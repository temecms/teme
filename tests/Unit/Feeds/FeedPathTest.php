<?php

declare(strict_types=1);

use App\Domain\Feeds\FeedPath;

it('reads a stored path as a feed address: its base, and whether it already has the canonical form', function (): void {
    // path => [base, canonical]
    $cases = [
        'feed' => ['', true],
        'category/news/feed' => ['category/news', true],
        'feed/rss2' => ['', false],
        'feed/atom' => ['', false],
        'rss' => ['', false],
        'category/news/atom' => ['category/news', false],
        'author/jane-doe/feed/rdf' => ['author/jane-doe', false],
        'a/feed/feed' => ['a', false],
        'rss/feed' => ['rss', true],
    ];
    $wrong = [];

    foreach ($cases as $path => [$base, $canonical]) {
        $feed = FeedPath::parse((string) $path);

        if ($feed === null || $feed->base !== $base || $feed->canonical !== $canonical) {
            $wrong[] = $path;
        }
    }

    expect($wrong)->toBe([]);
});

it('refuses a path that is no feed address', function (): void {
    $paths = ['', 'about', 'about/feeds', 'category/news', 'feed/extra', 'feed.xml', 'news/feedback', 'feed/atom/extra'];

    expect(array_values(array_filter($paths, fn (string $path): bool => FeedPath::parse($path) !== null)))->toBe([]);
});

it('gives a base\'s canonical feed address, always ending in a slash', function (): void {
    expect((new FeedPath('', true))->url())->toBe('/feed/')
        ->and((new FeedPath('category/news', false))->url())->toBe('/category/news/feed/')
        ->and((new FeedPath('author/jane-doe', true))->url())->toBe('/author/jane-doe/feed/');
});

it('matches the route\'s pattern for every address that parses, in any case, and for no other', function (): void {
    $matches = fn (string $path): bool => preg_match('~^'.FeedPath::ROUTE.'$~', $path) === 1;
    $feeds = ['feed', 'Feed', 'category/news/feed', 'feed/atom', 'a/rss2', 'a/b/c/FEED', 'a/feed/feed', 'comments/feed/rss'];
    $others = ['feeds', 'news', 'feed/extra', 'a/feedback', 'rss.xml', 'feed/atom/extra', 'category/news/'];

    expect(array_values(array_filter($feeds, fn (string $path): bool => ! $matches($path))))->toBe([])
        ->and(array_values(array_filter($others, $matches)))->toBe([]);
});

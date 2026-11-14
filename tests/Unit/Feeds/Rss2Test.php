<?php

declare(strict_types=1);

use App\Domain\Feeds\Feed;
use App\Domain\Feeds\FeedImage;
use App\Domain\Feeds\FeedItem;
use App\Domain\Feeds\Rss2;
use Carbon\CarbonImmutable;

/**
 * @param  list<FeedItem>  $items
 */
function rss2Sample(array $items, ?CarbonImmutable $updated = null): Feed
{
    return new Feed('The Ledger', 'https://example.test/', 'https://example.test/feed/', 'Local news', 'en-GB', $updated, $items, 'noindex, follow');
}

/**
 * @param  array<string, mixed>  $changes
 */
function rss2Item(array $changes = []): FeedItem
{
    return new FeedItem(...[
        'id' => 'teme:content/01jxharbour', 'title' => 'Harbour news', 'url' => 'https://example.test/harbour-news/', 'author' => 'Jane Doe',
        'published' => CarbonImmutable::parse('2026-10-05 08:30:00', 'UTC'), 'categories' => [], 'summary' => 'A short summary.', 'html' => null, 'image' => null,
        ...$changes,
    ]);
}

/**
 * @return list<string>
 */
function rss2Names(DOMXPath $feed, string $query): array
{
    return array_map(fn (DOMNode $node): string => $node->nodeName, iterator_to_array($feed->query($query) ?: []));
}

it('writes the prolog, the root with its four namespaces and the channel in its order, and nothing WordPress adds for old aggregators', function (): void {
    $xml = (new Rss2)->render(rss2Sample([rss2Item()], CarbonImmutable::parse('2026-10-05 08:30:00', 'UTC')));
    $feed = feedParse($xml);

    expect($xml)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">')
        ->and($xml)->not->toContain('<generator')->not->toContain('sy:')->not->toContain('<ttl')->not->toContain('wfw:')->not->toContain('slash:')
        ->and(rss2Names($feed, '/rss/channel/*[not(self::item)]'))->toBe(['title', 'atom:link', 'link', 'description', 'lastBuildDate', 'language'])
        ->and($feed->evaluate('string(/rss/channel/title)'))->toBe('The Ledger')
        ->and($feed->evaluate('string(/rss/channel/atom:link/@href)'))->toBe('https://example.test/feed/')
        ->and($feed->evaluate('string(/rss/channel/atom:link/@rel)'))->toBe('self')
        ->and($feed->evaluate('string(/rss/channel/atom:link/@type)'))->toBe('application/rss+xml')
        ->and($feed->evaluate('string(/rss/channel/link)'))->toBe('https://example.test/')
        ->and($feed->evaluate('string(/rss/channel/description)'))->toBe('Local news')
        ->and($feed->evaluate('string(/rss/channel/lastBuildDate)'))->toBe('Mon, 05 Oct 2026 08:30:00 +0000')
        ->and($feed->evaluate('string(/rss/channel/language)'))->toBe('en-GB');
});

it('writes an item with every element in its place, and the guid as an identifier that is not a link', function (): void {
    $item = rss2Item(['categories' => ['Local', 'Harbour'], 'html' => '<p>The body.</p>', 'image' => new FeedImage('https://example.test/storage/boats.webp', 'image/webp', 1280, 720)]);
    $feed = feedParse((new Rss2)->render(rss2Sample([$item])));

    expect(rss2Names($feed, '/rss/channel/item[1]/*'))->toBe(['title', 'link', 'dc:creator', 'pubDate', 'category', 'category', 'guid', 'description', 'content:encoded', 'media:content'])
        ->and($feed->evaluate('string(//item/title)'))->toBe('Harbour news')
        ->and($feed->evaluate('string(//item/link)'))->toBe('https://example.test/harbour-news/')
        ->and($feed->evaluate('string(//item/dc:creator)'))->toBe('Jane Doe')
        ->and($feed->evaluate('string(//item/pubDate)'))->toBe('Mon, 05 Oct 2026 08:30:00 +0000')
        ->and($feed->evaluate('string(//item/category[1])'))->toBe('Local')
        ->and($feed->evaluate('string(//item/category[2])'))->toBe('Harbour')
        ->and($feed->evaluate('string(//item/guid)'))->toBe('teme:content/01jxharbour')
        ->and($feed->evaluate('string(//item/guid/@isPermaLink)'))->toBe('false')
        ->and($feed->evaluate('string(//item/description)'))->toBe('A short summary.')
        ->and($feed->evaluate('string(//item/content:encoded)'))->toBe('<p>The body.</p>')
        ->and($feed->evaluate('string(//item/media:content/@url)'))->toBe('https://example.test/storage/boats.webp')
        ->and($feed->evaluate('string(//item/media:content/@medium)'))->toBe('image')
        ->and($feed->evaluate('string(//item/media:content/@type)'))->toBe('image/webp')
        ->and($feed->evaluate('string(//item/media:content/@width)'))->toBe('1280')
        ->and($feed->evaluate('string(//item/media:content/@height)'))->toBe('720');
});

it('leaves out what an item has not: its author, categories, body and image, and a type it does not know', function (): void {
    $bare = feedParse((new Rss2)->render(rss2Sample([rss2Item(['author' => null])])));
    $untyped = feedParse((new Rss2)->render(rss2Sample([rss2Item(['image' => new FeedImage('https://example.test/storage/x.bin', null, 640, 360)])])));

    expect(rss2Names($bare, '/rss/channel/item[1]/*'))->toBe(['title', 'link', 'pubDate', 'guid', 'description'])
        ->and($untyped->evaluate('count(//item/media:content/@type)'))->toBe(0.0)
        ->and($untyped->evaluate('string(//item/media:content/@width)'))->toBe('640');
});

it('stays well-formed whatever the text holds, and gives back what it was given less what XML cannot carry', function (): void {
    $hostile = "Fish & \"Chips\" <b>]]></b> \x08 \u{1F600}";
    $clean = "Fish & \"Chips\" <b>]]></b>  \u{1F600}";
    $item = rss2Item(['title' => $hostile, 'author' => $hostile, 'summary' => $hostile, 'categories' => [$hostile], 'html' => '<p>'.$hostile.'</p>']);
    $feed = feedParse((new Rss2)->render(rss2Sample([$item])));

    expect($feed->evaluate('string(//item/title)'))->toBe($clean)
        ->and($feed->evaluate('string(//item/dc:creator)'))->toBe($clean)
        ->and($feed->evaluate('string(//item/description)'))->toBe($clean)
        ->and($feed->evaluate('string(//item/category)'))->toBe($clean)
        ->and($feed->evaluate('string(//item/content:encoded)'))->toBe('<p>'.$clean.'</p>');
});

it('writes an empty feed as a valid channel with no lastBuildDate, and names itself and its type', function (): void {
    $format = new Rss2;
    $feed = feedParse($format->render(rss2Sample([])));

    expect(rss2Names($feed, '/rss/channel/*'))->toBe(['title', 'atom:link', 'link', 'description', 'language'])
        ->and($format->name())->toBe('rss2')
        ->and($format->contentType())->toBe('application/rss+xml; charset=UTF-8')
        ->and(Rss2::TYPE)->toBe('application/rss+xml');
});

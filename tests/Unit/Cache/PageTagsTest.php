<?php

declare(strict_types=1);

use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageTags;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

it('names each thing a page shows, and each list it draws, as the page cache tags them', function (): void {
    expect(PageTags::content('01jxharbour'))->toBe('content:01jxharbour')
        ->and(PageTags::listOf('post'))->toBe('list:post')
        ->and(PageTags::term('01jxnews'))->toBe('term:01jxnews')
        ->and(PageTags::filedUnder('category', '01jxnews'))->toBe('term:category:01jxnews')
        ->and(PageTags::person('7'))->toBe('person:7')
        ->and(PageTags::author('7'))->toBe('author:7')
        ->and(PageTags::media('01JXHERON'))->toBe('media:01JXHERON')
        ->and(PageTags::menu('main'))->toBe('menu:main')
        ->and(PageTags::region('default--after-content'))->toBe('region:default--after-content');
});

it('dates a moment by its year and its month', function (): void {
    expect(PageTags::dates(CarbonImmutable::parse('2026-03-07 10:00:00', 'UTC')))->toBe(['date:2026', 'date:2026:03'])
        ->and(PageTags::year(2026))->toBe('date:2026')
        ->and(PageTags::month(2026, 11))->toBe('date:2026:11');
});

it('tags an address in the form addresses are stored in, as a page\'s key does', function (): void {
    $key = PageKey::of(Request::create('/About/?s=heron'));

    expect(PageTags::url('about'))->toBe($key?->urlTag())
        ->and(PageTags::url('/about/'))->toBe($key?->urlTag())
        ->and(PageTags::url('contact'))->not->toBe($key?->urlTag());
});

it('tells a list\'s tag, whose pages are set aside when it changes, from a thing\'s, whose pages are removed (docs/studies/page-cache.md §3.9)', function (string $tag, bool $isList): void {
    expect(PageTags::isList($tag))->toBe($isList);
})->with([
    'a type\'s list' => ['list:post', true],
    'what is filed under a term' => ['term:category:01jxnews', true],
    'what a person wrote' => ['author:7', true],
    'a year' => ['date:2026', true],
    'a month' => ['date:2026:09', true],
    'a content item as shown' => ['content:01jxharbour', false],
    'a term as shown' => ['term:01jxnews', false],
    'a person as shown' => ['person:7', false],
    'a media item' => ['media:01JXHERON', false],
    'a menu' => ['menu:main', false],
    'a region' => ['region:default--after-content', false],
    'an address' => ['url:0123456789abcdef', false],
    'the site\'s settings' => ['settings', false],
    'the design' => ['theme', false],
    'every page' => ['*', false],
    'a tag core doesn\'t name yet' => ['feed', false],
]);

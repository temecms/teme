<?php

declare(strict_types=1);

use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Document\MetaTag;

it('writes each entry as its tag, every value escaped as Blade escapes text, a link\'s attributes in one order', function (): void {
    expect(MetaTag::name('description', 'Fish & "chips" <b>tonight</b>')->html())
        ->toBe('<meta name="description" content="Fish &amp; &quot;chips&quot; &lt;b&gt;tonight&lt;/b&gt;">')
        ->and(MetaTag::property('og:title', "Harbour's news")->html())
        ->toBe('<meta property="og:title" content="Harbour&#039;s news">')
        ->and((new LinkTag(LinkRelation::Alternate, 'https://example.test/feed/?a=1&b=2', 'application/rss+xml', 'News & "notes"', 'en-GB'))->html())
        ->toBe('<link rel="alternate" hreflang="en-GB" type="application/rss+xml" title="News &amp; &quot;notes&quot;" href="https://example.test/feed/?a=1&amp;b=2">')
        ->and((new LinkTag(LinkRelation::Canonical, 'https://example.test/harbour-news/'))->html())
        ->toBe('<link rel="canonical" href="https://example.test/harbour-news/">');
});

it('counts a meta key in any case as one tag, a canonical, prev or next as one link, and an alternate as one link an address (I-228)', function (): void {
    expect(MetaTag::name('Robots', 'noindex')->identity())->toBe(MetaTag::name('robots', 'index')->identity())
        ->and(MetaTag::name('robots', 'noindex')->identity())->not->toBe(MetaTag::property('robots', 'noindex')->identity())
        ->and((new LinkTag(LinkRelation::Next, 'https://example.test/page/2/'))->identity())
        ->toBe((new LinkTag(LinkRelation::Next, 'https://example.test/page/3/'))->identity())
        ->and((new LinkTag(LinkRelation::Alternate, 'https://example.test/feed/'))->identity())
        ->not->toBe((new LinkTag(LinkRelation::Alternate, 'https://example.test/category/news/feed/'))->identity());
});

it('refuses an entry that could break the head or make the browser load something (I-228)', function (string $case, string $exception): void {
    expect(fn () => match ($case) {
        'a rel off the allow-list' => new LinkTag(LinkRelation::from('stylesheet'), 'https://example.test/site.css'),
        'a script address' => new LinkTag(LinkRelation::Canonical, 'javascript:alert(1)'),
        'an address without its site' => new LinkTag(LinkRelation::Canonical, '/harbour-news/'),
        'an address that would end its attribute' => new LinkTag(LinkRelation::Canonical, 'https://example.test/"><script>'),
        'a type that isn\'t a media type' => new LinkTag(LinkRelation::Alternate, 'https://example.test/feed/', 'rss feed'),
        'a language that isn\'t a tag' => new LinkTag(LinkRelation::Alternate, 'https://example.test/fr/', hreflang: 'French!'),
        default => MetaTag::name('robots" onload="alert(1)', 'noindex'),
    })->toThrow($exception);
})->with([
    'a rel off the allow-list' => ['a rel off the allow-list', ValueError::class],
    'a script address' => ['a script address', InvalidArgumentException::class],
    'an address without its site' => ['an address without its site', InvalidArgumentException::class],
    'an address that would end its attribute' => ['an address that would end its attribute', InvalidArgumentException::class],
    'a type that isn\'t a media type' => ['a type that isn\'t a media type', InvalidArgumentException::class],
    'a language that isn\'t a tag' => ['a language that isn\'t a tag', InvalidArgumentException::class],
    'a key that would end its attribute' => ['a key that would end its attribute', InvalidArgumentException::class],
]);

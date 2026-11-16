<?php

declare(strict_types=1);

use App\Domain\Cache\PageKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('is the scheme, the host and the path exactly as asked', function (): void {
    expect(PageKey::of(Request::create('http://Example.COM/About/'))?->url)->toBe('http://example.com/About/')
        ->and(PageKey::of(Request::create('https://example.com/about/'))?->url)->toBe('https://example.com/about/');
});

it('keeps a port that isn\'t the scheme\'s own', function (): void {
    expect(PageKey::of(Request::create('http://example.com:8080/about/'))?->url)->toBe('http://example.com:8080/about/');
});

it('keeps s from the query, and nothing else', function (): void {
    expect(PageKey::of(Request::create('/about/?utm_source=news&s=heron&fbclid=abc&ref=home'))?->url)->toBe('http://localhost/about/?s=heron')
        ->and(PageKey::of(Request::create('/about/?utm_source=news&gclid=abc'))?->url)->toBe('http://localhost/about/');
});

it('writes s one way however the reader sent it', function (): void {
    expect(PageKey::of(Request::create('/?s=heron+watch'))?->url)->toBe('http://localhost/?s=heron%20watch')
        ->and(PageKey::of(Request::create('/?s=heron%20watch'))?->url)->toBe('http://localhost/?s=heron%20watch');
});

it('keeps an empty search apart from no search', function (): void {
    expect(PageKey::of(Request::create('/?s='))?->url)->toBe('http://localhost/?s=')
        ->and(PageKey::of(Request::create('/'))?->url)->toBe('http://localhost/');
});

it('keeps a page number, which is in the path', function (): void {
    expect(PageKey::of(Request::create('/page/2/?s=heron'))?->url)->toBe('http://localhost/page/2/?s=heron');
});

it('has no key for a search that isn\'t plain text', function (): void {
    expect(PageKey::of(Request::create('/?s[]=heron')))->toBeNull();
});

it('tags a page by its address in stored form, whatever its case or query', function (): void {
    $tag = PageKey::of(Request::create('/about/'))?->urlTag();

    expect($tag)->toStartWith('url:')->toHaveLength(20)
        ->and(PageKey::of(Request::create('/About/?s=heron'))?->urlTag())->toBe($tag)
        ->and(PageKey::of(Request::create('/contact/'))?->urlTag())->not->toBe($tag);
});

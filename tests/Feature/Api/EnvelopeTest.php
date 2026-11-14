<?php

declare(strict_types=1);

use App\Domain\Api\ApiResponse;
use App\Domain\Api\Envelope;
use App\Http\Api\Middleware\EnsureApiOn;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
    Route::middleware(['api', EnsureApiOn::class, 'throttle:api'])->prefix('api/v1')
        ->get('_things/{id}', fn () => null)->name('api.v1.probe-thing');
    app('router')->getRoutes()->refreshNameLookups();
});

it('writes a list as data, meta.limit and links.self and links.next, exactly', function (): void {
    $last = ApiResponse::list([['id' => 'a'], ['id' => 'b']], 2, 'https://example.com/api/v1/items?limit=2', null);
    $more = ApiResponse::list([['id' => 'a']], 1, 'https://example.com/api/v1/items?limit=1', 'https://example.com/api/v1/items?after=x&limit=1');
    $none = ApiResponse::list([], 20, 'https://example.com/api/v1/items', null);

    expect($last->getContent())->toBe('{"data":[{"id":"a"},{"id":"b"}],"meta":{"limit":2},"links":{"self":"https://example.com/api/v1/items?limit=2","next":null}}')
        ->and($more->getContent())->toBe('{"data":[{"id":"a"}],"meta":{"limit":1},"links":{"self":"https://example.com/api/v1/items?limit=1","next":"https://example.com/api/v1/items?after=x&limit=1"}}')
        ->and($none->getContent())->toBe('{"data":[],"meta":{"limit":20},"links":{"self":"https://example.com/api/v1/items","next":null}}')
        ->and($last->headers->get('Content-Type'))->toBe('application/json');
});

it('writes one thing\'s empty data, meta and links as objects, never lists', function (): void {
    expect(ApiResponse::one([], [])->getContent())->toBe('{"data":{},"links":{}}')
        ->and(ApiResponse::one(['id' => 'a'], [], [])->getContent())->toBe('{"data":{"id":"a"},"meta":{},"links":{}}')
        ->and(ApiResponse::one(['id' => 'a'], ['self' => 'https://example.com/api/v1/items/a'], ['total' => 1])->getContent())
        ->toBe('{"data":{"id":"a"},"meta":{"total":1},"links":{"self":"https://example.com/api/v1/items/a"}}');
});

it('writes an API address absolute, its query sorted by name and encoded by RFC 3986', function (): void {
    expect(Envelope::link('api.v1.probe-thing', ['id' => 'abc']))->toBe('https://example.com/api/v1/_things/abc')
        ->and(Envelope::link('api.v1.probe-thing', ['id' => 'abc'], ['type' => 'movie,post', 'limit' => '2', 'after' => 'x y~z/é']))
        ->toBe('https://example.com/api/v1/_things/abc?after=x%20y~z%2F%C3%A9&limit=2&type=movie%2Cpost')
        ->and(Envelope::link('api.v1.probe-thing', ['id' => 'abc'], ['slug' => '', 'b' => '1']))
        ->toBe('https://example.com/api/v1/_things/abc?b=1&slug=');
});

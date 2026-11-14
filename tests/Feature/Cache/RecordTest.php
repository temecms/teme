<?php

declare(strict_types=1);

use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cache\SignedInMarker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->folder = cacheOn();

    // Public routes that record tags, as the reads behind a page do.
    Route::middleware('public')->prefix('cache-probe')->group(function (): void {
        Route::get('tagged', function () {
            app(RenderRecord::class)->tag('list:harbour', 'content:ferry', PageTags::EVERYTHING, 'content:ferry');

            return response('<p>Tagged</p>');
        });
        Route::get('many', function () {
            app(RenderRecord::class)->tag(...array_map(fn (int $n): string => sprintf('content:%026d', $n), range(1, 200)));

            return response('<p>Many</p>');
        });
    });
});

afterEach(fn () => cacheOff($this->folder));

it('stores the tags a page was drawn with, its address\'s first, the rest sorted and each once, never *', function (): void {
    addrGet('/cache-probe/tagged')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheEntry('/cache-probe/tagged')?->tags)->toBe([
        (string) PageKey::of(Request::create('/cache-probe/tagged'))?->urlTag(),
        'content:ferry',
        'list:harbour',
    ]);
});

it('notes nothing while no page the cache may store is being drawn', function (): void {
    $record = app(RenderRecord::class);
    $record->tag('content:ferry');

    expect($record->tags())->toBe([]);

    cacheGet('/cache-probe/tagged', [SignedInMarker::COOKIE => '1'])->assertHeader('X-Teme-Cache', 'BYPASS (signed in)');

    expect($record->tags())->toBe([]);
});

it('lists no more of a page\'s tags in X-Teme-Cache-Tags than a proxy will take, and stores them all', function (): void {
    $header = (string) addrGet('/cache-probe/many')->assertHeader('X-Teme-Cache', 'MISS')->headers->get('X-Teme-Cache-Tags');

    expect(strlen($header))->toBeLessThan(2100)
        ->and($header)->toEndWith(' more)')
        ->and(cacheEntry('/cache-probe/many')?->tags)->toHaveCount(201);
});

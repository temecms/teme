<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\RenderRecord;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->folder = cacheOnStatic();

    // A page during whose drawing the ferry timetable is published, a second later.
    Route::middleware('public')->get('cache-static-race', function () {
        app(RenderRecord::class)->tag('content:ferry');
        test()->travel(1)->seconds();
        app(PageCache::class)->purge(['content:ferry']);

        return response('<p>The timetable as it was</p>');
    });
});

afterEach(fn () => cacheOff($this->folder));

it('writes the page a reader\'s request draws as the file the web server serves next', function (): void {
    // In production, where a page's own robots header is the only one (I-291).
    $this->app['env'] = 'production';

    pageArticle($this->author, 'Big story');

    $miss = addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheStaticFile('/big-story/'))->toBe($miss->getContent())
        ->and(DB::table('page_cache_files')->pluck('path')->all())->toBe(['/big-story/']);
});

it('writes no file for a page drawn while one of its tags was purged, nor for a HEAD (D5)', function (): void {
    pageArticle($this->author, 'Big story');

    addrGet('/cache-static-race')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (purged while drawn)');
    cacheGet('/big-story/', method: 'HEAD')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (head)');

    expect(cacheStaticFile('/cache-static-race'))->toBeNull()
        ->and(cacheStaticFile('/big-story/'))->toBeNull()
        ->and(DB::table('page_cache_files')->count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Http\Site\Middleware\MarkCacheable;
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
    $this->folder = cacheOn();

    // Public routes that each answer one way, to try every rule on its own.
    Route::middleware('public')->prefix('cache-probe')->group(function (): void {
        Route::get('plain', fn () => response('<p>Plain</p>'));
        Route::get('cookie', fn () => response('<p>Cookie</p>')->cookie('flavour', 'oat', 10));
        Route::get('private', fn () => response('<p>Private</p>')->header('Cache-Control', 'private, max-age=60'));
        Route::get('no-store', fn () => response('<p>No store</p>')->header('Cache-Control', 'no-store'));
        Route::get('vary-cookie', fn () => response('<p>Vary</p>')->header('Vary', 'Cookie'));
        Route::get('vary-encoding', fn () => response('<p>Vary</p>')->header('Vary', 'Accept-Encoding'));
        Route::get('encoded', fn () => response('<p>Encoded</p>')->header('Content-Encoding', 'gzip'));
        Route::get('json', fn () => response()->json(['story' => 'Harbour']));
        Route::get('streamed', fn () => response()->stream(function (): void {
            echo '<p>Streamed</p>';
        }));
        Route::get('forbidden', fn () => abort(403));
        Route::get('unavailable', fn () => abort(503));
    });

    // Routes marked cacheable (I-464), and one that isn't, for the types beside HTML.
    Route::middleware(['public', MarkCacheable::class])->prefix('cache-probe/marked')->group(function (): void {
        Route::get('feed', fn () => response('<rss version="2.0"><channel><title>Harbour</title></channel></rss>', 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']));
        Route::get('sitemap', fn () => response('<?xml version="1.0" encoding="UTF-8"?><urlset></urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8']));
        Route::get('robots', fn () => response("User-agent: *\nDisallow: /admin/\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']));
        Route::get('json', fn () => response()->json(['story' => 'Harbour']));
        Route::get('image', fn () => response('GIF89a', 200, ['Content-Type' => 'image/gif']));
        Route::get('script', fn () => response('alert(1)', 200, ['Content-Type' => 'text/javascript']));
    });
    Route::middleware('public')->get('cache-probe/xml', fn () => response('<urlset></urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8']));
});

afterEach(fn () => cacheOff($this->folder));

it('stores a plain page from any public route', function (): void {
    addrGet('/cache-probe/plain')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/cache-probe/plain')->assertOk()->assertHeader('X-Teme-Cache', 'HIT')->assertSee('Plain');
});

it('never stores a response that sets a cookie', function (): void {
    addrGet('/cache-probe/cookie')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (sets a cookie)');
    addrGet('/cache-probe/cookie')->assertHeader('X-Teme-Cache', 'BYPASS (sets a cookie)');
});

it('never stores a redirect', function (): void {
    pageArticle($this->author, 'Big story');

    addrGet('/big-story')->assertStatus(301)->assertHeader('X-Teme-Cache', 'BYPASS (redirect)');
    addrGet('/big-story')->assertStatus(301)->assertHeader('X-Teme-Cache', 'BYPASS (redirect)');
});

it('never stores a server error', function (): void {
    addrGet('/cache-probe/unavailable')->assertStatus(503)->assertHeader('X-Teme-Cache', 'BYPASS (server error)');
});

it('never stores any other status but 200 and 404', function (): void {
    addrGet('/cache-probe/forbidden')->assertForbidden()->assertHeader('X-Teme-Cache', 'BYPASS (status 403)');
});

it('keeps a "not found" page ten seconds', function (): void {
    addrGet('/no-such-page/')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS')
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=10');
    addrGet('/no-such-page/')->assertNotFound()->assertHeader('X-Teme-Cache', 'HIT');

    $this->travel(PageCache::NOT_FOUND_LIFETIME)->seconds();

    addrGet('/no-such-page/')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');
});

it('never stores a response marked private or not to be kept', function (): void {
    addrGet('/cache-probe/private')->assertHeader('X-Teme-Cache', 'BYPASS (private)');
    addrGet('/cache-probe/no-store')->assertHeader('X-Teme-Cache', 'BYPASS (private)');
});

it('never stores a response that varies by what the key doesn\'t hold, and keeps one that varies by encoding', function (): void {
    addrGet('/cache-probe/vary-cookie')->assertHeader('X-Teme-Cache', 'BYPASS (vary)');

    addrGet('/cache-probe/vary-encoding')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/cache-probe/vary-encoding')->assertHeader('X-Teme-Cache', 'HIT')->assertHeader('Vary', 'Accept-Encoding');
});

it('never stores an encoded body', function (): void {
    addrGet('/cache-probe/encoded')->assertHeader('X-Teme-Cache', 'BYPASS (encoded)');
});

it('never stores anything but HTML', function (): void {
    addrGet('/cache-probe/json')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (not html)');
});

it('never stores a streamed response', function (): void {
    addrGet('/cache-probe/streamed')->assertHeader('X-Teme-Cache', 'BYPASS (streamed)');
});

it('never stores a page core\'s plain fallback drew for a broken theme', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/broken')]]);
    themeActivate('broken');

    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (degraded render)');
    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (degraded render)');
});

it('never keeps search\'s rate-limit headers', function (): void {
    searchPublished($this->author, 'Harbour notes', 'The lifeboat crew launched at dawn.');

    // Not even on the drawn copy, which a shared cache in front may keep for everyone.
    addrGet('/?s=lifeboat')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertHeaderMissing('X-RateLimit-Limit');

    expect(array_map('strtolower', array_keys(cacheEntry('/?s=lifeboat')?->headers ?? [])))
        ->not->toContain('x-ratelimit-limit')
        ->not->toContain('x-ratelimit-remaining');

    addrGet('/?s=lifeboat')->assertHeader('X-Teme-Cache', 'HIT')
        ->assertHeaderMissing('X-RateLimit-Limit')
        ->assertHeaderMissing('X-RateLimit-Remaining');
});

it('stores the XML and plain text a route marks cacheable, and serves it as it came: a feed, a sitemap, robots.txt (I-464)', function (string $path, string $type): void {
    $miss = addrGet("/cache-probe/marked/{$path}")->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $hit = addrGet("/cache-probe/marked/{$path}")->assertOk()->assertHeader('X-Teme-Cache', 'HIT')->assertHeader('Content-Type', $type);

    expect((string) $miss->getContent())->not->toBe('')
        ->and($hit->getContent())->toBe($miss->getContent());
})->with([
    'a feed' => ['feed', 'application/rss+xml; charset=UTF-8'],
    'a sitemap' => ['sitemap', 'application/xml; charset=UTF-8'],
    'robots.txt' => ['robots', 'text/plain; charset=UTF-8'],
]);

it('never stores XML or plain text from a route that doesn\'t mark it', function (): void {
    addrGet('/cache-probe/xml')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (not html)');
});

it('never stores anything else a marked route answers: an image, a script (I-464)', function (string $path): void {
    addrGet("/cache-probe/marked/{$path}")->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (not xml or text)');
    addrGet("/cache-probe/marked/{$path}")->assertHeader('X-Teme-Cache', 'BYPASS (not xml or text)');
})->with(['image', 'script']);

it('gives what a marked route stores no file, since the web server would serve it as a page (I-537)', function (): void {
    cacheOff($this->folder);
    $this->folder = cacheOnStatic();
    // In production, where a page gets a file at all.
    $this->app['env'] = 'production';

    addrGet('/cache-probe/marked/feed')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/cache-probe/plain')->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheStaticFile('/cache-probe/marked/feed'))->toBeNull()
        ->and(cacheStaticFile('/cache-probe/plain'))->toBe('<p>Plain</p>')
        ->and(DB::table('page_cache_files')->pluck('path')->all())->toBe(['/cache-probe/plain']);
});

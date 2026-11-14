<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Mid-year, so no new year falls inside any life a test sets (Task 2).
    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('keeps a page as long as page_cache.lifetime says', function (): void {
    config(['page_cache.lifetime' => 120]);
    pageArticle($this->author, 'Big story');

    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS')->assertHeader('X-Teme-Cache-Ttl', '120');

    $this->travel(119)->seconds();
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'HIT');

    $this->travel(1)->seconds();
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');
});

it('keeps a page an hour by default, unless PAGE_CACHE_LIFETIME says otherwise (question 2)', function (): void {
    expect((string) file_get_contents(config_path('page_cache.php')))->toContain("'lifetime' => (int) env('PAGE_CACHE_LIFETIME', 3600)")
        // Named, never set, as PAGE_CACHE_ENABLED is.
        ->and((string) file_get_contents(base_path('.env.example')))->toContain('# PAGE_CACHE_LIFETIME=')->not->toMatch('/^PAGE_CACHE_LIFETIME=/m');
});

it('keeps a search\'s page ten minutes at most, and a "not found" page ten seconds (D7)', function (): void {
    config(['page_cache.lifetime' => 3600]);

    addrGet('/?s=lifeboat')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertHeader('X-Teme-Cache-Ttl', '600');
    addrGet('/no-such-page/')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS')->assertHeader('X-Teme-Cache-Ttl', '10');
});

it('keeps a search\'s page no longer than any page, when pages live less than ten minutes', function (): void {
    config(['page_cache.lifetime' => 120]);

    addrGet('/?s=lifeboat')->assertOk()->assertHeader('X-Teme-Cache-Ttl', '120');
});

it('tells a shared cache in front to keep a page a minute at most, however long Teme keeps it (D6)', function (): void {
    config(['page_cache.lifetime' => 3600]);
    pageArticle($this->author, 'Big story');

    addrGet('/big-story/')->assertHeader('X-Teme-Cache-Ttl', '3600')->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=60');

    $this->travel(3590)->seconds();

    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'HIT')->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=10');
});

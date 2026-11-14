<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    cmItem(User::factory()->create(['name' => 'Jane Doe'])->assignRole('author'), 'Harbour news');
    $this->folder = cacheOnStatic();
});

afterEach(fn () => cacheOff($this->folder));

it('tells search engines to keep away from every response outside production: a page, a "not found", the admin\'s sign-in, core\'s endpoint (I-291)', function (string $uri): void {
    expect(addrGet($uri)->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
})->with(['/harbour-news/', '/no-such-page/', '/admin/login', '/_teme/session']);

it('puts the stricter value in place of a page\'s own outside production', function (): void {
    addrGet('/?s=harbour')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('adds nothing in production: a page carries no X-Robots-Tag, and a search keeps its own (I-291)', function (): void {
    // isProduction() reads $app['env'], not config('app.env').
    $this->app['env'] = 'production';

    addrGet('/harbour-news/')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    addrGet('/?s=harbour')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
});

it('stores the header with a page outside production, so a hit carries it and the static-file driver writes no file there (I-537)', function (): void {
    addrGet('/harbour-news/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    addrGet('/harbour-news/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect(cacheEntry('/harbour-news/')?->headers['X-Robots-Tag'] ?? null)->toBe(['noindex, nofollow'])
        ->and(cacheStaticFile('/harbour-news/'))->toBeNull()
        ->and(DB::table('page_cache_files')->count())->toBe(0);
});

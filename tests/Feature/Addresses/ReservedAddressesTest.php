<?php

declare(strict_types=1);

use App\Domain\Addresses\Paths;
use App\Domain\Cache\PageCache;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('keeps feed, sitemap.xml and robots.txt for the site\'s own routes: no item\'s address and no address setting may start with one', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $someone = '01jvm0000000000000000000aa';

    expect(Paths::conflict('feed', 'content', $someone))->toBe('The address /feed/ is used by the site itself.')
        ->and(Paths::conflict('feed/harbour', 'content', $someone))->toBe('The address /feed/harbour/ is used by the site itself.')
        ->and(Paths::conflict('feeds', 'content', $someone))->toBeNull()
        ->and(array_map(Paths::taken(...), ['feed', 'sitemap.xml', 'robots.txt', 'admin', 'feeds']))->toBe([true, true, true, true, false])
        ->and(fn () => addrChange($admin, ['bases' => ['category' => 'feed']]))
        ->toThrow(ValidationException::class, 'Addresses starting /feed/ are used by the site itself.');
});

it('lets the page cache store what those routes answer, unlike the router\'s own addresses', function (): void {
    config(['app.url' => 'http://localhost']);

    expect(PageCache::bypass(Request::create('/feed/')))->toBeNull()
        ->and(PageCache::bypass(Request::create('/sitemap.xml')))->toBeNull()
        ->and(PageCache::bypass(Request::create('/robots.txt')))->toBeNull()
        ->and(PageCache::bypass(Request::create('/admin/')))->toBe('reserved');
});

it('keeps api and llms.txt for the site itself, as the feed and the sitemap are', function (): void {
    expect(array_map(Paths::taken(...), ['api', 'llms.txt', 'apis', 'api-docs', 'llms']))->toBe([true, true, false, false, false]);
});

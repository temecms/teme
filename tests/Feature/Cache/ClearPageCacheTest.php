<?php

declare(strict_types=1);

use App\Domain\Cache\Operations\ClearPageCache;
use App\Domain\Cache\Operations\Input\ClearPageCacheInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->folder = cacheOn();
    $this->clear = fn (User $actor): mixed => app(ClearPageCache::class)->execute(new ClearPageCacheInput((string) $actor->id));
    $this->cleared = fn (): array => DB::table('security_events')->where('type', 'page_cache.cleared')->pluck('user_id')->all();
});

afterEach(fn () => cacheOff($this->folder));

it('removes every stored page once the change commits, and records who cleared it (I-467)', function (): void {
    cachePage('/harbour-news/');
    cachePage('/');
    cachePage('/category/news/');

    expect(($this->clear)($this->admin))->toBeTrue()
        ->and(cacheKept('/harbour-news/', '/', '/category/news/'))->toBe([])
        ->and(($this->cleared)())->toBe([$this->admin->id]);
});

it('keeps every page when the change rolls back (rule 6)', function (): void {
    cachePage('/harbour-news/');

    cacheRolledBack(fn () => ($this->clear)($this->admin));

    expect(cacheKept('/harbour-news/'))->toBe(['/harbour-news/'])
        ->and(($this->cleared)())->toBe([]);
});

it('writes nothing for anyone but an administrator (authorization)', function (string $role): void {
    cachePage('/harbour-news/');
    $person = User::factory()->create()->assignRole($role);

    expect(fn () => ($this->clear)($person))->toThrow(AuthorizationException::class, 'Not permitted to clear the page cache.')
        ->and(cacheKept('/harbour-news/'))->toBe(['/harbour-news/'])
        ->and(($this->cleared)())->toBe([]);
})->with(['editor', 'author', 'contributor']);

it('refuses while the page cache is off, and records nothing', function (): void {
    config(['page_cache.enabled' => false]);

    expect(fn () => ($this->clear)($this->admin))
        ->toThrow(fn (ValidationException $refused) => expect($refused->errors())->toBe(['cache' => ['The page cache is off, so there’s nothing to clear.']]))
        ->and(($this->cleared)())->toBe([]);
});

it('clears from its screen, and says so', function (): void {
    cachePage('/harbour-news/');

    $this->actingAs($this->admin)->from('/admin/tools/page-cache')->post('/admin/tools/page-cache/clear')
        ->assertRedirect('/admin/tools/page-cache')
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Page cache cleared. Each page is drawn afresh on its next visit.');

    expect(cacheKept('/harbour-news/'))->toBe([])
        ->and(($this->cleared)())->toBe([$this->admin->id]);
});

it('answers 403 at the screen and its button to anyone but an administrator', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);

    $this->actingAs($person)->get('/admin/tools/page-cache')->assertForbidden();
    $this->actingAs($person)->post('/admin/tools/page-cache/clear')->assertForbidden();

    expect(($this->cleared)())->toBe([]);
})->with(['editor', 'author', 'contributor']);

it('shows whether the cache is on, the CDN in front, and who cleared it last here (I-467)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:14:00', 'UTC'));
    $gone = User::factory()->create(['name' => 'Bo Gone'])->assignRole('administrator');
    $screen = fn (): mixed => $this->actingAs($this->admin)->get('/admin/tools/page-cache')->assertOk();

    $screen()->assertInertia(fn (Assert $page) => $page
        ->component('tools/page-cache')
        ->where('on', true)
        ->where('cdn', null)
        ->where('cleared', null)
        ->where('siteTimezone', 'UTC'));

    ($this->clear)($this->admin);
    config(['page_cache.cdn.driver' => 'cloudflare']);

    $screen()->assertInertia(fn (Assert $page) => $page
        ->where('cdn', 'Cloudflare')
        ->where('cleared', ['at' => '2026-10-08T09:14:00+00:00', 'by' => 'Ada Admin']));

    $this->travel(1)->minutes();
    ($this->clear)($gone);
    $gone->delete();
    config(['page_cache.enabled' => false]);

    $screen()->assertInertia(fn (Assert $page) => $page
        ->where('on', false)
        ->where('cleared', ['at' => '2026-10-08T09:15:00+00:00', 'by' => 'a removed account']));
});

it('names where each driver keeps pages, and caps every life at a page\'s (I-500)', function (string $driver, int $lifetime, string $keptIn, string $page, string $search, string $notFound): void {
    config(['page_cache.driver' => $driver, 'page_cache.lifetime' => $lifetime]);

    $this->actingAs($this->admin)->get('/admin/tools/page-cache')->assertOk()
        ->assertInertia(fn (Assert $screen) => $screen
            ->where('keptIn', $keptIn)
            ->where('lives', ['any' => $page, 'search' => $search, 'notFound' => $notFound]));
})->with([
    'files' => ['file', 3600, 'Files on this server', '1 hour', '10 minutes', '10 seconds'],
    'static files' => ['static', 300, 'Files on this server, sent by the web server itself', '5 minutes', '5 minutes', '10 seconds'],
    'redis' => ['redis', 7200, 'Redis, shared by every server', '2 hours', '10 minutes', '10 seconds'],
    'a life of a second' => ['file', 1, 'Files on this server', '1 second', '1 second', '1 second'],
]);

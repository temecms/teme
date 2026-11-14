<?php

declare(strict_types=1);

use App\Domain\Cache\FilePageStore;
use App\Domain\Cache\PageStore;
use App\Domain\Status\Checks\PageCacheFolderShared;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Backups\HealthyBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('warns administrators when a process removes pages from another folder than the one they are stored in', function (): void {
    HealthyBackups::seed();

    cachePage('/harbour/');

    // The queue worker, on a folder of its own.
    $worker = sys_get_temp_dir().'/teme-page-cache-worker-'.getmypid().'-'.uniqid();
    config(['page_cache.stores.file.path' => $worker]);
    app(PageStore::class)->purge(['content:ferry']);
    config(['page_cache.stores.file.path' => $this->folder]);

    expect(app(StatusChecks::class)->forUser(User::factory()->create()->assignRole('administrator'))?->message)
        ->toContain('Some changes can show up to a minute late')
        ->toContain('clears saved pages from a folder this web server doesn’t read');

    cacheOff($worker);
});

it('stays quiet while every process shares the folder', function (): void {
    cachePage('/harbour/');

    app(PageStore::class)->purge(['content:ferry']);
    app(PageStore::class)->prune(now()->getTimestamp());
    app(PageStore::class)->flush();

    expect(Cache::get(FilePageStore::UNSHARED_KEY))->toBeNull()
        ->and(app(PageCacheFolderShared::class)->banner())->toBeNull();
});

it('names an emptied folder afresh when the next page is stored', function (): void {
    cachePage('/harbour/');
    (new Filesystem)->deleteDirectory($this->folder);
    cachePage('/ferries/');

    app(PageStore::class)->purge(['content:ferry']);

    expect(Cache::get(FilePageStore::UNSHARED_KEY))->toBeNull();
});

it('names its folder again after the cache store is cleared, so a process on another folder is still caught', function (): void {
    cachePage('/harbour/');

    // What php artisan cache:clear leaves: the marker on disk, its name gone from the cache store.
    Cache::forget(FilePageStore::FOLDER_KEY);
    cachePage('/ferries/');

    // The queue worker, on a folder of its own.
    $worker = sys_get_temp_dir().'/teme-page-cache-worker-'.getmypid().'-'.uniqid();
    config(['page_cache.stores.file.path' => $worker]);
    app(PageStore::class)->purge(['content:ferry']);
    config(['page_cache.stores.file.path' => $this->folder]);

    expect(Cache::get(FilePageStore::UNSHARED_KEY))->not->toBeNull();

    cacheOff($worker);
});

it('says a change can show as late as a page lives', function (): void {
    config(['page_cache.lifetime' => 3600]);
    Cache::put(FilePageStore::UNSHARED_KEY, ['folder' => $this->folder.'-worker', 'host' => 'worker-1', 'at' => now()->toIso8601String()], now()->addDay());

    expect(app(PageCacheFolderShared::class)->banner()?->message)
        ->toStartWith('Some changes can show up to an hour late: worker-1 clears saved pages');
});

it('keeps its pages in storage/page-cache by default, out of storage/framework, which a container may keep to itself (question 4)', function (): void {
    expect((string) file_get_contents(config_path('page_cache.php')))->toContain("'path' => env('PAGE_CACHE_PATH', storage_path('page-cache'))")
        ->and(is_file(storage_path('page-cache/.gitignore')))->toBeTrue()
        ->and(is_file(storage_path('framework/page-cache/.gitignore')))->toBeFalse();
});

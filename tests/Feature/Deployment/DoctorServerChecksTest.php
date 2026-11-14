<?php

declare(strict_types=1);

use App\Domain\Cache\FilePageStore;
use App\Domain\Deployment\Health\Checks\PageCacheFolder;
use App\Domain\Deployment\Health\Checks\QueueHeartbeat;
use App\Domain\Deployment\Health\Checks\SchedulerHeartbeat;
use App\Domain\Deployment\Health\Checks\Storage;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Deployment\Health\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Fixtures\Deployment\HealthRows;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/teme-doctor-'.Str::lower(Str::random(8));
    File::ensureDirectoryExists($this->root.'/page-cache');
    File::put($this->root.'/not-a-folder', 'a file');
    config(['page_cache.driver' => 'file', 'page_cache.stores.file.path' => $this->root.'/page-cache']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('says every folder Teme writes to can be written', function (): void {
    $storage = 'storage'.DIRECTORY_SEPARATOR;

    expect(app(Storage::class)->examine())->toEqual([new Finding(Severity::Ok, "Every folder Teme writes to can be written: {$storage}app, {$storage}framework, {$this->root}/page-cache.")]);
});

it('names each folder that can\'t be written, an error everywhere', function (): void {
    config(['page_cache.stores.file.path' => $this->root.'/not-a-folder/page-cache']);

    expect(app(Storage::class)->examine())->toEqual([new Finding(Severity::Error, "{$this->root}/not-a-folder/page-cache can't be written.", 'Give the user PHP runs as write access to it; in the image, mount the volume the recipe names there.')]);
});

it('agrees when this process sees the folder the web server stores pages in (I-480)', function (): void {
    File::put($this->root.'/page-cache/.teme-folder', 'abc123');
    Cache::forever(FilePageStore::FOLDER_KEY, 'abc123');

    expect(FilePageStore::folderNameAt($this->root.'/page-cache'))->toBe('abc123')
        ->and(app(PageCacheFolder::class)->examine())->toEqual([new Finding(Severity::Ok, 'This process sees the folder the web server stores pages in.')]);
});

it('warns when this process sees another folder, and when a removal reached one in the last day', function (): void {
    File::put($this->root.'/page-cache/.teme-folder', 'abc123');
    Cache::forever(FilePageStore::FOLDER_KEY, 'xyz789');
    Cache::put(FilePageStore::UNSHARED_KEY, ['folder' => '/elsewhere', 'host' => 'worker-1', 'at' => '2026-10-20T11:00:00+00:00'], now()->addDay());
    $fix = 'Mount the same page-cache volume at PAGE_CACHE_PATH in every role.';

    expect(app(PageCacheFolder::class)->examine())->toEqual([
        new Finding(Severity::Warning, "This process's page-cache folder, {$this->root}/page-cache, isn't the one the web server stores pages in, so pages it removes stay served until they expire.", $fix),
        new Finding(Severity::Warning, "On worker-1, the page cache removed pages from a folder they aren't stored in, in the last day.", $fix),
    ]);
});

it('says there is nothing to compare until the cache store is told a folder\'s name', function (?string $marker): void {
    if ($marker !== null) {
        File::put($this->root.'/page-cache/.teme-folder', $marker);
    }

    expect(app(PageCacheFolder::class)->examine())->toEqual([new Finding(Severity::Info, "No page has been stored since the cache was last cleared, so there's nothing to compare yet.")]);
})->with([
    'no page stored yet' => [null],
    'a marker, but the cache cleared since' => ['abc123'],
]);

it('says when the scheduler last ran, and warns after five minutes, or when it never has, in every environment', function (): void {
    $fix = 'Start the scheduler role, which runs php artisan schedule:work.';

    expect(app(SchedulerHeartbeat::class)->examine())->toEqual([new Finding(Severity::Warning, "The scheduler hasn't run on this site yet: scheduled posts, backups and other timed work wait until it runs.", $fix)]);

    HealthRows::beat(Heartbeats::SCHEDULER, CarbonImmutable::parse('2026-10-20 11:58:00'));

    expect(app(SchedulerHeartbeat::class)->examine())->toEqual([new Finding(Severity::Ok, 'The scheduler last ran 2 minutes ago.')]);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:10:00'));

    expect(app(SchedulerHeartbeat::class)->examine())->toEqual([new Finding(Severity::Warning, 'The scheduler last ran 12 minutes ago: scheduled posts, backups and other timed work wait until it runs.', $fix)]);
});

it('warns for each queue no worker takes, says when each other queue last finished a job, and notes when there is neither', function (): void {
    expect(app(QueueHeartbeat::class)->examine())->toEqual([new Finding(Severity::Info, 'No worker has finished a job yet, and none is waiting.')]);

    HealthRows::job('long', CarbonImmutable::parse('2026-10-20 11:46:00'));
    HealthRows::beat(Heartbeats::QUEUE_PREFIX.'default', CarbonImmutable::parse('2026-10-20 11:58:00'));

    expect(app(QueueHeartbeat::class)->examine())->toEqual([
        new Finding(Severity::Warning, 'Jobs on the long queue have waited 14 minutes with no worker taking them.', 'Start a worker that reads it: php artisan queue:work with --queue=long.'),
        new Finding(Severity::Ok, 'A worker last finished a job on the default queue 2 minutes ago.'),
    ]);
});

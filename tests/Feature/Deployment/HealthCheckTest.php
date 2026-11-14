<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\CheckHealth;
use App\Domain\Deployment\Health\HealthCheckFailed;
use App\Domain\Deployment\Health\HealthFolders;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Fixtures\Deployment\UnreachableDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Folders a test can make unwritable without chmod, which root ignores.
    $this->root = sys_get_temp_dir().'/teme-health-'.Str::lower(Str::random(8));
    File::ensureDirectoryExists($this->root.'/page-cache');
    File::put($this->root.'/not-a-folder', 'a file');
    config(['page_cache.driver' => 'file', 'page_cache.stores.file.path' => $this->root.'/page-cache']);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('passes when the database answers, every migration has run and every folder can be written (I-378)', function (): void {
    expect(fn () => app(CheckHealth::class)->handle(new DiagnosingHealth))->not->toThrow(Throwable::class);
});

it('asks the database one question', function (): void {
    DB::enableQueryLog();

    app(CheckHealth::class)->handle(new DiagnosingHealth);

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('fails when a migration this code ships hasn\'t run', function (): void {
    DB::table('migrations')->where('migration', DB::table('migrations')->max('migration'))->delete();

    expect(fn () => app(CheckHealth::class)->handle(new DiagnosingHealth))
        ->toThrow(HealthCheckFailed::class, "The health check failed:\n- 1 migration hasn't run on the database.");
});

it('passes when the database holds migrations this code doesn\'t ship, as after a rollback to an older image', function (): void {
    DB::table('migrations')->insert(['migration' => '2099_01_01_000000_a_later_release', 'batch' => 99]);

    expect(fn () => app(CheckHealth::class)->handle(new DiagnosingHealth))->not->toThrow(Throwable::class);
});

it('fails when the database doesn\'t answer, and still checks every folder', function (): void {
    config(['page_cache.stores.file.path' => $this->root.'/not-a-folder/page-cache']);
    $message = '';

    try {
        UnreachableDatabase::during(fn () => app(CheckHealth::class)->handle(new DiagnosingHealth));
    } catch (HealthCheckFailed $failed) {
        $message = $failed->getMessage();
    }

    expect($message)->toContain("\n- The database didn't answer: ")
        ->toContain("\n- {$this->root}/not-a-folder/page-cache can't be written.");
});

it('fails naming each folder that can\'t be written', function (): void {
    config(['page_cache.stores.file.path' => $this->root.'/not-a-folder/page-cache']);

    expect(fn () => app(CheckHealth::class)->handle(new DiagnosingHealth))
        ->toThrow(HealthCheckFailed::class, "\n- {$this->root}/not-a-folder/page-cache can't be written.");
});

it('counts a folder as writable when it can be written, or, not made yet, when its nearest existing parent can', function (string $relative, bool $writable): void {
    expect(HealthFolders::writable($this->root.'/'.$relative))->toBe($writable);
})->with([
    'an existing folder' => ['page-cache', true],
    'a folder not made yet' => ['page-cache/static/2026', true],
    'a file where the folder should be' => ['not-a-folder', false],
    'a folder under a file' => ['not-a-folder/page-cache', false],
]);

it('checks storage/app, storage/framework and the page cache\'s folder, and the static folder when that driver is on', function (): void {
    expect(HealthFolders::paths())->toBe([storage_path('app'), storage_path('framework'), $this->root.'/page-cache']);

    config(['page_cache.driver' => 'static', 'page_cache.stores.static.path' => $this->root.'/page-cache/static']);

    expect(HealthFolders::paths())->toBe([storage_path('app'), storage_path('framework'), $this->root.'/page-cache', $this->root.'/page-cache/static']);
});

<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Doctor;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\HealthCheckFailed;
use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\Deployment\HealthRows;
use Tests\Fixtures\Deployment\QuietJob;
use Tests\Fixtures\Deployment\StubCheck;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/teme-wiring-'.Str::lower(Str::random(8));
    File::ensureDirectoryExists($this->root.'/page-cache');
    File::put($this->root.'/not-a-folder', 'a file');
    // Laravel's /up rethrows under debug; production never runs in it.
    config(['app.debug' => false, 'page_cache.driver' => 'file', 'page_cache.stores.file.path' => $this->root.'/page-cache']);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('answers /up with 200 when the site is ready (I-378)', function (): void {
    $this->get('/up')->assertOk();
});

it('answers /up with 500 and no detail when something is wrong, and reports every problem', function (): void {
    Exceptions::fake();
    DB::table('migrations')->where('migration', DB::table('migrations')->max('migration'))->delete();
    config(['page_cache.stores.file.path' => $this->root.'/not-a-folder/page-cache']);

    $this->get('/up')->assertStatus(500)->assertDontSee('migration')->assertDontSee('not-a-folder');
    $this->getJson('/up')->assertStatus(500)->assertExactJson(['status' => 'down']);

    Exceptions::assertReported(fn (HealthCheckFailed $failed): bool => str_contains($failed->getMessage(), "- 1 migration hasn't run on the database.")
        && str_contains($failed->getMessage(), "- {$this->root}/not-a-folder/page-cache can't be written."));
});

it('beats for the queue each time a worker finishes a job, through the listener boot registers', function (): void {
    Queue::connection('database')->push(new QuietJob, '', 'default');

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(array_keys(app(Heartbeats::class)->queues()))->toBe(['default']);
});

it('never beats for a job the request runs itself', function (): void {
    Queue::connection('sync')->push(new QuietJob);

    expect(DB::table('heartbeats')->count())->toBe(0);
});

it('shows administrators of a production site the scheduler\'s banner first, then the queue\'s, and authors neither', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $author = User::factory()->create()->assignRole('author');
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    app()->instance('env', 'production');
    HealthRows::job('default', CarbonImmutable::parse('2026-10-20 11:40:00'));

    expect(app(StatusChecks::class)->forUser($admin)?->message)->toStartWith("Scheduled posts aren't publishing:")
        ->and(app(StatusChecks::class)->forUser($author)?->message ?? '')->not->toContain('Scheduled posts');

    HealthRows::beat(Heartbeats::SCHEDULER, CarbonImmutable::parse('2026-10-20 11:59:00'));

    expect(app(StatusChecks::class)->forUser($admin)?->message)->toStartWith("Uploaded images' sizes and other background work are waiting:");
});

it('keeps one doctor for the whole process, with core\'s checks and room for more', function (): void {
    expect(app(Doctor::class))->toBe(app(Doctor::class))
        // Only core's own: a later part registers more into the same singleton.
        ->and(array_slice(app(Doctor::class)->keys(), 0, count(Doctor::CORE)))->toBe(array_keys(Doctor::CORE));

    app()->instance(StubCheck::class, new StubCheck('Stub', [new Finding(Severity::Ok, 'Registered after boot.')]));
    app(Doctor::class)->register('stub', StubCheck::class);
    Artisan::call('teme:doctor');
    $output = Artisan::output();

    expect($output)->toContain('  ok       Stub: Registered after boot.'.PHP_EOL);
});

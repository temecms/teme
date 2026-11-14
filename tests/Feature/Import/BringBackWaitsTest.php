<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Input\StartPageParentsJobInput;
use App\Domain\Import\Operations\StartPageParentsJob;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Import\Tracking\PageParentsJobKind;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Tree\ImportedPages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    importFake();
    $this->source = importSource();
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->pages = ImportedPages::import($this->source, $this->admin, [10 => ['About', 'about', 0, 0], 11 => ['Team', 'team', 10, 0]]);
    // Bring back's steps on the database queue, where only bbwWork() runs them.
    config(['queue.default' => 'database']);
});

/**
 * A worker on the database queue taking the next step once it falls due,
 * the clock moved on to it; answers whether there was one.
 */
function bbwWork(): bool
{
    $next = DB::table('jobs')->orderBy('available_at')->orderBy('id')->first();

    if ($next === null) {
        return false;
    }

    $wait = (int) $next->available_at - now()->getTimestamp();

    if ($wait > 0) {
        test()->travel($wait)->seconds();
    }

    app()->forgetScopedInstances();
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => (string) $next->queue, '--once' => true, '--sleep' => 0]);

    return true;
}

function bbwJob(string $sourceId): TrackedJob
{
    return TrackedJob::query()->where('kind', PageParentsJobKind::KEY)->where('run_id', $sourceId)->latest('id')->firstOrFail();
}

it('waits as long as an import of the site runs, then carries on', function (): void {
    // An import of the site starts just after Bring back did.
    app(StartPageParentsJob::class)->execute(new StartPageParentsJobInput($this->source->id, (string) $this->admin->id));
    $run = ImportRun::query()->create([
        'source_id' => $this->source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Running, 'phase' => 'content', 'phase_token' => 'token', 'high_water_at' => now(), 'started_at' => now(),
    ]);

    // Eight looks a minute apart, the import still running: each puts the step back, and none fails it.
    foreach (range(1, 8) as $look) {
        expect(bbwWork())->toBeTrue("look {$look}");
    }

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(bbwJob($this->source->id)->state)->not->toBe(JobState::Failed)
        ->and($this->pages[11]->refresh()->parent_id)->toBeNull();

    $run->update(['state' => ImportRunState::Stopped, 'stop_reason' => 'cancelled', 'phase' => null, 'phase_token' => null, 'ended_at' => now()]);

    while (bbwWork()) {
        // Every step left runs.
    }

    expect(bbwJob($this->source->id)->state)->toBe(JobState::Finished)
        ->and($this->pages[11]->refresh()->parent_id)->toBe($this->pages[10]->id);
});

it('still fails after three real errors', function (): void {
    app(StartPageParentsJob::class)->execute(new StartPageParentsJobInput($this->source->id, (string) $this->admin->id));
    // Every step meets an error of its own, as when the database goes away.
    app()->bind(ImportRuns::class, fn (): never => throw new RuntimeException('The database went away.'));

    $tries = 0;

    while (bbwWork()) {
        $tries++;
        expect($tries)->toBeLessThanOrEqual(3);
    }

    expect($tries)->toBe(3)
        ->and(bbwJob($this->source->id)->state)->toBe(JobState::Failed)
        ->and(bbwJob($this->source->id)->message)->toBe('Bringing back page parents stopped. Retry starts it again.');
});

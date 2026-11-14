<?php

declare(strict_types=1);

use App\Domain\Import\ImportEngine;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Jobs\ImportListPage;
use App\Domain\Import\Jobs\RunImportStep;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\ImportObject;
use App\Domain\Import\Operations\Input\ImportObjectInput;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Operations\StopImportRun;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Import\FakeCompanion;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->fake = importFake();
    importPeople();
    $this->source = importSource($this->admin);

    // A run started with the queue held, so its jobs wait for the test to run them.
    $this->held = function (array $choices = []): ImportRun {
        Queue::fake();
        $result = scElevated(fn (): array => app(StartImportRun::class)->execute(new StartImportRunInput($this->source->id, ImportRunKind::Full, ['pause_ms' => 0], $choices, (string) $this->admin->id)));

        return ImportRun::query()->findOrFail($result['runId']);
    };

    $this->outcomes = fn (ImportRun $run): array => ImportMapEntry::query()->where('run_id', $run->id)->orderByRaw('object_id::int')
        ->get()->mapWithKeys(fn (ImportMapEntry $entry): array => [$entry->object_id => $entry->outcome->value])->all();

    // A run started with its jobs on the database queue, where only ($this->work)() runs them.
    $this->queued = function (): ImportRun {
        config(['queue.default' => 'database']);

        return importRun($this->source, $this->admin);
    };

    // A worker on the database queue, running each job when it falls due, the clock moved on to
    // it, until none is left; the waits the clock was moved on by, in seconds, in order. The clock
    // stands still between moves, so a wait is the delay the job asked for: a running clock can
    // pass a second between a job's release and this read, and the wait reads one short (issue 424).
    $this->work = function (): array {
        $this->freezeSecond();
        $waits = [];

        for ($turn = 0; ($next = DB::table('jobs')->orderBy('available_at')->orderBy('id')->first()) !== null; $turn++) {
            expect($turn)->toBeLessThan(100);
            $wait = (int) $next->available_at - now()->getTimestamp();

            if ($wait > 0) {
                $waits[] = $wait;
                $this->travel($wait)->seconds();
            }

            Artisan::call('queue:work', ['connection' => 'database', '--queue' => (string) $next->queue, '--once' => true]);
        }

        return $waits;
    };
});

it('imports every page of a list in ID order, one map row per object, then its step in two jobs, and finishes', function (): void {
    $run = importRun($this->source, $this->admin);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($run->phase)->toBeNull()
        ->and(($this->outcomes)($run))->toBe(['1' => 'created', '2' => 'created', '5' => 'created', '9' => 'skipped', '12' => 'failed'])
        // Warnings: the custom role, the subscriber's reason, and the step's two notes.
        ->and([$run->created_count, $run->updated_count, $run->skipped_count, $run->failed_count, $run->warning_count])->toBe([3, 0, 1, 1, 4])
        ->and(DB::table('fixture_people')->orderBy('wordpress_id')->pluck('wordpress_id')->all())->toBe([1, 2, 5])
        ->and(array_column(array_column($this->fake->requestsTo('users'), 'query'), 'after_id'))->toBe(['0', '2', '9'])
        ->and(DB::table('import_messages')->where('run_id', $run->id)->whereNull('map_id')->pluck('code')->all())->toEqualCanonicalizing(['site.title', 'site.read'])
        ->and(DB::table('import_messages')->where('code', 'source.unreadable')->value('message'))->toBe('WordPress couldn’t load this one (RuntimeException), so it wasn’t imported.')
        ->and(DB::table('security_events')->where('type', 'import.ended')->count())->toBe(1);
});

it('imports again only what changed, as updated, replacing its warnings', function (): void {
    $first = importRun($this->source, $this->admin);
    $users = json_decode((string) file_get_contents(base_path('tests/Fixtures/Import/Companion/users.json')), true)['items'];
    $users[1]['published_posts'] = 31;               // grace: a count the list ignores
    $users[2]['display_name'] = 'The SEO Desk';      // seo: a real change
    $this->fake->items('users', $users);

    $second = importRun($this->source, $this->admin);

    expect(($this->outcomes)($second))->toBe(['5' => 'updated', '12' => 'failed'])
        ->and(ImportMapEntry::query()->where('object_id', '2')->value('run_id'))->toBe($first->id)
        ->and(DB::table('fixture_people')->where('wordpress_id', 5)->value('name'))->toBe('The SEO Desk')
        ->and(DB::table('import_messages')->where('code', 'user.custom-role')->pluck('run_id')->all())->toBe([$second->id]);
});

it('records a failed object and carries on with the rest; the next run tries it again', function (): void {
    $failed = importRun($this->source, $this->admin, choices: ['fail' => 'grace']);

    expect(($this->outcomes)($failed)['2'])->toBe('failed')
        ->and(($this->outcomes)($failed)['5'])->toBe('created')
        ->and(DB::table('import_messages')->where('code', 'fixture.refused')->value('level'))->toBe('error');

    $again = importRun($this->source, $this->admin);

    expect(($this->outcomes)($again)['2'])->toBe('created')
        ->and(DB::table('import_messages')->where('code', 'fixture.refused')->count())->toBe(0);
});

it('leaves neither the object nor its map row as imported when its import breaks, and says so in plain words', function (): void {
    $run = importRun($this->source, $this->admin, choices: ['explode' => 'grace']);

    expect(DB::table('fixture_people')->where('wordpress_id', 2)->exists())->toBeFalse()
        ->and(ImportMapEntry::query()->where('object_id', '2')->value('status'))->toBe(ImportStatus::Failed)
        ->and(DB::table('import_messages')->where('code', 'object.unexpected')->value('message'))->toBe('Teme couldn’t import this because of an unexpected error (RuntimeException). The application log has the details.')
        ->and($run->state)->toBe(ImportRunState::Finished);
});

it('stops the run in the phase\'s own words, keeping what was done', function (): void {
    $run = importRun($this->source, $this->admin, choices: ['stop' => 'grace']);

    expect($run->state)->toBe(ImportRunState::Stopped)
        ->and($run->stop_reason)->toBe('fixture-stop')
        ->and($run->stop_message)->toBe('Stopped by the fixture at grace.')
        ->and(($this->outcomes)($run))->toBe(['1' => 'created'])
        ->and(DB::table('import_messages')->where('code', 'site.title')->exists())->toBeFalse();
});

it('stops the run when the old site refuses its password, saying what to do', function (): void {
    $this->fake->answer('users', 401, ['code' => 'incorrect_password', 'message' => 'Refused.', 'data' => ['status' => 401]]);

    $run = importRun($this->source, $this->admin);

    expect($run->state)->toBe(ImportRunState::Stopped)
        ->and($run->stop_reason)->toBe('credentials')
        ->and($run->stop_message)->toBe('WordPress didn’t accept this user name and application password. If both are right, the site’s server may be removing the sign-in header before WordPress sees it. Then carry on the import.');
});

it('stops the run when part of it failed for good', function (): void {
    $run = ($this->held)();

    app(ImportEngine::class)->phaseEnded($run->id, (string) $run->phase_token, JobActor::person($this->admin->id), 1);

    expect($run->fresh()->state)->toBe(ImportRunState::Stopped)
        ->and($run->fresh()->stop_reason)->toBe('page-failed')
        ->and($run->fresh()->stop_message)->toBe('Part of this import failed three times, or the old site didn’t answer it for about an hour, so it stopped. The application log has the details.');
});

it('waits out the old site not answering, longer each time, and carries on when it answers again (#255)', function (): void {
    foreach (range(1, 4) as $try) {
        $this->fake->unreachable('users');
    }

    $this->fake->unreachable('settings');

    $run = ($this->queued)();
    $waits = ($this->work)();

    expect($run->fresh()->state)->toBe(ImportRunState::Finished)
        ->and(($this->outcomes)($run))->toBe(['1' => 'created', '2' => 'created', '5' => 'created', '9' => 'skipped', '12' => 'failed'])
        // The page waited four times, then the step once.
        ->and($waits)->toBe([30, 60, 120, 300, 30])
        ->and(array_column(array_column($this->fake->requestsTo('users'), 'query'), 'after_id'))->toBe(['0', '0', '0', '0', '0', '2', '9'])
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('stops the run when the old site hasn’t answered a page for about an hour (#255)', function (): void {
    foreach (range(1, 10) as $try) {
        $this->fake->unreachable('users');
    }

    $run = ($this->queued)();
    $waits = ($this->work)();

    expect($waits)->toBe([30, 60, 120, 300, 300, 600, 600, 900, 900])
        ->and($this->fake->requestsTo('users'))->toHaveCount(10)
        ->and($run->fresh()->state)->toBe(ImportRunState::Stopped)
        ->and($run->fresh()->stop_reason)->toBe('page-failed')
        ->and($run->fresh()->stop_message)->toBe('Part of this import failed three times, or the old site didn’t answer it for about an hour, so it stopped. The application log has the details.');
});

it('still stops the run after three failures that aren’t the old site’s, without waiting an hour (#255)', function (): void {
    foreach (range(1, 3) as $try) {
        $this->fake->breaks('users');
    }

    $run = ($this->queued)();
    $waits = ($this->work)();

    expect($waits)->toBe([30, 120])
        ->and($this->fake->requestsTo('users'))->toHaveCount(3)
        ->and($run->fresh()->state)->toBe(ImportRunState::Stopped)
        ->and($run->fresh()->stop_reason)->toBe('page-failed');
});

it('advances a phase once, though its end is reported twice', function (): void {
    $run = ($this->held)();
    $actor = JobActor::person($this->admin->id);
    $token = (string) $run->phase_token;

    app(ImportEngine::class)->phaseEnded($run->id, $token, $actor, 0);
    $moved = $run->fresh();
    app(ImportEngine::class)->phaseEnded($run->id, $token, $actor, 0);

    expect([$run->phase, $moved->phase])->toBe(['fixture-people', 'fixture-notes'])
        ->and($run->fresh()->phase)->toBe('fixture-notes')
        ->and($run->fresh()->phase_token)->toBe($moved->phase_token)
        ->and(Queue::pushed(RunImportStep::class)->count())->toBe(1)
        ->and(DB::table('job_batches')->count())->toBe(2);
});

it('does nothing for a job of an attempt that was stopped', function (): void {
    $run = ($this->held)();
    $page = Queue::pushed(ImportListPage::class)->first();
    app(StopImportRun::class)->execute(new StopImportRunInput($run->id, (string) $this->admin->id));

    cmRunJob($page);

    expect(ImportMapEntry::query()->count())->toBe(0)
        ->and($this->fake->requestsTo('users'))->toBe([]);
});

it('imports an object once though two jobs reach it', function (): void {
    $run = ($this->held)();
    $context = app(ImportRuns::class)->context($run, JobActor::person($this->admin->id));
    $item = ['id' => 1, 'login' => 'ada', 'display_name' => 'Ada Lovelace', 'roles' => ['administrator']];
    $import = fn (): ?ImportOutcome => app(ImportObject::class)->execute(new ImportObjectInput((string) $run->phase_token, 'fixture-people', $context, SourceRef::of('user', 1), $item, ImportMap::hashOf($item), null));

    expect($import())->toBe(ImportOutcome::Created)
        ->and($import())->toBeNull()
        ->and(ImportMapEntry::query()->count())->toBe(1)
        ->and(DB::table('fixture_people')->count())->toBe(1);
});

it('carries the operator\'s actor through a run\'s jobs, which a worker runs with no elevation of its own (I-392)', function (): void {
    Queue::fake();
    $result = scElevated(fn (): array => app(StartImportRun::class)->execute(new StartImportRunInput($this->source->id, ImportRunKind::Full, ['pause_ms' => 0])));
    $page = Queue::pushed(ImportListPage::class)->first();

    expect($page->actor->isOperator())->toBeTrue();

    app()->instance(Elevation::class, new DeniedElevation);
    cmRunJob($page);

    expect(ImportMapEntry::query()->where('run_id', $result['runId'])->count())->toBe(2)
        ->and(Queue::pushed(ImportListPage::class)->last()->actor->isOperator())->toBeTrue()
        ->and(Queue::pushed(ImportListPage::class)->last()->afterId)->toBe(2);
});

it('keeps job payloads to IDs: a page job carries none of the old site\'s data', function (): void {
    ($this->held)();
    cmRunJob(Queue::pushed(ImportListPage::class)->first());
    $next = Queue::pushed(ImportListPage::class)->last();

    expect(serialize($next))->not->toContain('ada@old.example')
        ->not->toContain('$wp$')
        ->not->toContain(FakeCompanion::PASSWORD)
        ->and($next->afterId)->toBe(2);
});

it('releases what a phase prepared, whether its object was imported, skipped or failed', function (): void {
    importRun($this->source, $this->admin, choices: ['fail' => 'grace']);

    expect(Cache::get('fixture-released'))->toBe(4);
});

it('purges the page cache once, when the run ends (I-332)', function (): void {
    $folder = cacheOn();
    cacheShown(['/ferry/' => ['content:ferry']]);

    importRun($this->source, $this->admin);

    expect(cacheKept('/ferry/'))->toBe([]);
    cacheOff($folder);
});

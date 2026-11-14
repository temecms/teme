<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Jobs\BringBackPageParents;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\ForgetImportSource;
use App\Domain\Import\Operations\Input\ForgetImportSourceInput;
use App\Domain\Import\Operations\Input\StartPageParentsJobInput;
use App\Domain\Import\Operations\StartPageParentsJob;
use App\Domain\Import\Tracking\PageParentsJobKind;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\RetryJob;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Tree\ImportedPages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    importFake();
    $this->source = importSource();
    $this->admin = User::factory()->create()->assignRole('administrator');
    Queue::fake([BringBackPageParents::class]);
});

/**
 * Four pages as an import before this slice left them: Team under About,
 * People under Team, Other at the top.
 *
 * @return array<int, Content>
 */
function bbPages(object $test): array
{
    return ImportedPages::import($test->source, $test->admin, [
        10 => ['About', 'about', 0, 0], 11 => ['Team', 'team', 10, 0], 12 => ['People', 'people', 11, 0], 13 => ['Other', 'other', 0, 1],
    ]);
}

/**
 * Starts bringing back the site's page parents, as the operator unless a person is given.
 */
function bbStart(string $sourceId, ?User $actor = null): string
{
    $start = fn (): string => app(StartPageParentsJob::class)->execute(new StartPageParentsJobInput($sourceId, $actor === null ? null : (string) $actor->id));

    return $actor === null ? scElevated($start) : $start();
}

/**
 * Runs the steps the fake queue holds from the $from-th on, as a worker
 * would, each step's own next one included; answers how many it holds now.
 */
function bbWork(int $from = 0): int
{
    while (($jobs = Queue::pushed(BringBackPageParents::class)->values())->count() > $from) {
        app()->forgetScopedInstances();
        app()->call([$jobs[$from++], 'handle']);
    }

    return $from;
}

function bbJob(string $sourceId): TrackedJob
{
    return TrackedJob::query()->where('kind', PageParentsJobKind::KEY)->where('run_id', $sourceId)->latest('id')->firstOrFail();
}

/**
 * Every page's place and every address, to show nothing changed.
 *
 * @return array{0: list<mixed>, 1: list<mixed>}
 */
function bbState(): array
{
    return [DB::table('contents')->orderBy('id')->get(['id', 'parent_id', 'position'])->all(), DB::table('paths')->orderBy('path')->get()->all()];
}

function bbRunning(string $sourceId): void
{
    ImportRun::query()->create([
        'source_id' => $sourceId, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Running, 'phase' => 'content', 'phase_token' => 'token', 'high_water_at' => now(), 'started_at' => now(),
    ]);
}

it('brings back a site\'s page tree as a tracked job, 200 pages a step', function (): void {
    $pages = [1000 => ['Root', 'root', 0, 0]];

    foreach (range(1001, 1248) as $id) {
        $pages[$id] = ["Page {$id}", "page-{$id}", 1000, 0];
    }

    // One whose parent wasn't imported, which stays where it is.
    $pages[1249] = ['Stray', 'stray', 99, 0];

    $items = ImportedPages::import($this->source, $this->admin, $pages);

    bbStart($this->source->id, $this->admin);
    $job = bbJob($this->source->id);

    expect($job->label)->toBe("Bringing back page parents from {$this->source->address}")
        ->and($job->total)->toBe(250)
        ->and(bbWork())->toBe(2);

    $job->refresh();

    expect($job->state)->toBe(JobState::Finished)
        ->and($job->done)->toBe(250)
        ->and($job->message)->toBe("Put 248 pages back under their parents. 1 stayed where they are; php artisan teme:import:page-parents {$this->source->address} --notes says why.")
        ->and($items[1248]->refresh()->parent_id)->toBe($items[1000]->id)
        ->and(addrCurrent('content', $items[1248]->id))->toBe('root/page-1248');
});

it('says how many pages it put back, one in the singular', function (array $pages, string $words): void {
    ImportedPages::import($this->source, $this->admin, $pages);
    bbStart($this->source->id, $this->admin);
    bbWork();

    expect(bbJob($this->source->id)->message)->toBe($words);
})->with([
    'one' => [[10 => ['About', 'about', 0, 0], 11 => ['Team', 'team', 10, 0]], 'Put 1 page back under their parents.'],
    'two' => [[10 => ['About', 'about', 0, 0], 11 => ['Team', 'team', 10, 0], 12 => ['History', 'history', 10, 1]], 'Put 2 pages back under their parents.'],
]);

it('works after the site\'s password is forgotten', function (): void {
    $pages = bbPages($this);
    scElevated(fn () => app(ForgetImportSource::class)->execute(new ForgetImportSourceInput($this->source->id, null)));

    bbStart($this->source->id, $this->admin);
    bbWork();

    expect($pages[12]->refresh()->parent_id)->toBe($pages[11]->id)
        ->and(addrCurrent('content', $pages[12]->id))->toBe('about/team/people');
});

it('refuses while a run of the site is running, and when there\'s nothing to bring back', function (Closure $case, string $words): void {
    bbPages($this);
    $case($this);
    $before = bbState();

    expect(fn () => bbStart($this->source->id, $this->admin))->toThrow(ValidationException::class, str_replace(':site', $this->source->address, $words));

    expect(TrackedJob::query()->where('kind', PageParentsJobKind::KEY)->count())->toBe(0)
        ->and(Queue::pushed(BringBackPageParents::class))->toHaveCount(0)
        ->and(bbState())->toEqual($before);
})->with([
    'running' => [fn ($test) => bbRunning($test->source->id), 'An import of :site is running. Bring back page parents once it ends.'],
    'nothing' => [fn ($test) => DB::table('import_map')->where('source_id', $test->source->id)->update(['facts' => DB::raw("jsonb_set(facts, '{parent}', '0')")]), 'No page imported from :site sat under another page, so there\'s nothing to bring back.'],
]);

it('runs as the person who asked, with no elevation', function (): void {
    $pages = bbPages($this);
    app()->instance(Elevation::class, new DeniedElevation);

    bbStart($this->source->id, $this->admin);
    bbWork();

    expect(bbJob($this->source->id)->started_by)->toBe($this->admin->id)
        ->and(bbJob($this->source->id)->state)->toBe(JobState::Finished)
        ->and($pages[11]->refresh()->parent_id)->toBe($pages[10]->id);
});

it('carries on from the start on Retry and changes nothing twice', function (): void {
    $pages = bbPages($this);
    bbStart($this->source->id, $this->admin);
    $ran = bbWork();
    $after = bbState();

    // Brought back again, the queue gives up on its first step: the job fails, and Retry starts it again.
    bbStart($this->source->id, $this->admin);
    Queue::pushed(BringBackPageParents::class)->values()[$ran++]->failed(new RuntimeException('Gone.'));
    expect(bbJob($this->source->id)->state)->toBe(JobState::Failed)
        ->and(bbJob($this->source->id)->message)->toBe('Bringing back page parents stopped. Retry starts it again.');

    app(RetryJob::class)->execute(new RetryJobInput(bbJob($this->source->id)->id, (string) $this->admin->id));
    bbWork($ran);

    expect(bbState())->toEqual($after)
        ->and(bbJob($this->source->id)->state)->toBe(JobState::Finished)
        ->and(bbJob($this->source->id)->message)->toBe('Put 2 pages back under their parents.')
        ->and($pages[12]->refresh()->parent_id)->toBe($pages[11]->id);
});

it('refuses someone who may not import, and starts nothing', function (): void {
    bbPages($this);
    $author = User::factory()->create()->assignRole('author');
    $before = bbState();

    expect(fn () => bbStart($this->source->id, $author))->toThrow(AuthorizationException::class);

    expect(TrackedJob::query()->count())->toBe(0)
        ->and(Queue::pushed(BringBackPageParents::class))->toHaveCount(0)
        ->and(bbState())->toEqual($before);
});

it('waits while a run of the site is running', function (): void {
    $pages = bbPages($this);
    bbStart($this->source->id, $this->admin);
    bbRunning($this->source->id);

    $step = Queue::pushed(BringBackPageParents::class)->first()->withFakeQueueInteractions();
    app()->call([$step, 'handle']);

    $step->assertReleased(60);
    expect($pages[11]->refresh()->parent_id)->toBeNull()
        ->and(bbJob($this->source->id)->state)->not->toBe(JobState::Finished);
});

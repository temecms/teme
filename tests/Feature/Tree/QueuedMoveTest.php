<?php

declare(strict_types=1);

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Addresses\Operations\Input\RebuildPathsInput;
use App\Domain\Addresses\Operations\RebuildPaths;
use App\Domain\Contents\Jobs\MoveSubtreeAddresses;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Tree\SubtreeJobKind;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\RetryJob;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
    app()->forgetScopedInstances();
});

/**
 * The tracked job moving the pages under the page.
 */
function queuedJob(string $rootId): TrackedJob
{
    return TrackedJob::query()->where('kind', SubtreeJobKind::KEY)->where('run_id', $rootId)->latest('id')->firstOrFail();
}

/**
 * Runs the next step the fake queue holds, as a worker would, and answers
 * whether there was one.
 */
function queuedStep(): bool
{
    static $ran = [];
    $next = collect(Queue::pushed(MoveSubtreeAddresses::class))->first(fn (MoveSubtreeAddresses $job): bool => ! in_array(spl_object_id($job), $ran, true));

    if ($next === null) {
        return false;
    }

    $ran[] = spl_object_id($next);
    app()->forgetScopedInstances();
    app()->call([$next, 'handle']);

    return true;
}

/**
 * Every address the pages under About have now that starts with the prefix.
 */
function queuedAt(string $prefix): int
{
    return DB::table('paths')->where('target_type', 'content')->where('is_current', true)->where('path', 'like', $prefix.'%')->count();
}

it('moves every page under a renamed page in steps of 500, the jobs indicator counting them', function (): void {
    // Five of About's pages have an address; 1,050 more make three steps.
    PageTree::many($this->tree->about, 1050, withAddresses: true);

    addrRename($this->tree->about, 'about-us', $this->tree->editor);

    $job = queuedJob($this->tree->about->id);

    expect(queuedAt('about-us/'))->toBe(1055)
        ->and(queuedAt('about/'))->toBe(0)
        ->and($job->state)->toBe(JobState::Finished)
        ->and($job->total)->toBe(1055)
        ->and($job->done)->toBe(1055)
        ->and($job->message)->toBe('Moved the addresses of 1055 pages under “About”.');
});

it('answers each page at its old address until the job reaches it', function (): void {
    Queue::fake([MoveSubtreeAddresses::class]);
    $ids = PageTree::many($this->tree->about, 600, withAddresses: true);
    $last = $ids[array_key_last($ids)];

    addrRename($this->tree->about, 'about-us', $this->tree->editor);

    expect(addrGet('/about/team/')->getStatusCode())->toBe(200)
        ->and(addrCurrent('content', $last))->toBe('about/many-600');

    queuedStep();

    $response = addrGet('/about/team/');

    expect($response->getStatusCode())->toBe(301)
        ->and(addrLocation($response))->toBe('/about-us/team/')
        ->and(addrCurrent('content', $last))->toBe('about/many-600')
        ->and(queuedJob($this->tree->about->id)->done)->toBe(500);

    queuedStep();

    expect(addrCurrent('content', $last))->toBe('about-us/many-600')
        ->and(queuedJob($this->tree->about->id)->state)->toBe(JobState::Finished);
});

it('stops when the job is cancelled', function (): void {
    Queue::fake([MoveSubtreeAddresses::class]);
    PageTree::many($this->tree->about, 60, withAddresses: true);
    addrRename($this->tree->about, 'about-us', $this->tree->editor);

    app(CancelJob::class)->execute(new CancelJobInput(queuedJob($this->tree->about->id)->id, (string) $this->tree->editor->id));
    queuedStep();

    expect(queuedAt('about-us/'))->toBe(0)
        ->and(queuedAt('about/'))->toBe(65)
        ->and(queuedJob($this->tree->about->id)->state)->toBe(JobState::Cancelled);
});

it('runs as the person who made the change, with no elevation', function (): void {
    PageTree::many($this->tree->about, 60, withAddresses: true);
    $this->app->instance(Elevation::class, new DeniedElevation);

    addrRename($this->tree->about, 'about-us', $this->tree->editor);

    expect(queuedAt('about-us/'))->toBe(65)
        ->and(queuedJob($this->tree->about->id)->started_by)->toBe($this->tree->editor->id)
        ->and(queuedJob($this->tree->about->id)->state)->toBe(JobState::Finished);
});

it('names the pages it kept, at most five, in its closing words', function (): void {
    Queue::fake([MoveSubtreeAddresses::class]);
    $ids = PageTree::many($this->tree->about, 60, withAddresses: true);
    addrRename($this->tree->about, 'about-us', $this->tree->editor);

    // While the job waits, six others take the addresses the first six would move to.
    foreach (range(1, 6) as $n) {
        $term = typesTerm($this->tree->admin, 'category', "Holder {$n}");
        DB::table('paths')->where('target_type', 'term')->where('target_id', $term->id)->where('is_current', true)->update(['path' => "about-us/many-{$n}"]);
    }

    queuedStep();

    expect(addrCurrent('content', $ids[0]))->toBe('about/many-1')
        ->and(addrCurrent('content', $ids[6]))->toBe('about-us/many-7')
        ->and(queuedJob($this->tree->about->id)->message)->toBe("Moved the addresses of 59 pages under “About”, and kept 6 at their old address because the new one is someone else's: “Many 1”, “Many 2”, “Many 3”, “Many 4”, “Many 5” and 1 more.");
});

it('refuses a subtree step from someone who may not edit the page, and writes nothing', function (): void {
    $before = DB::table('paths')->orderBy('path')->get()->all();
    DB::table('contents')->where('id', $this->tree->about->id)->update(['slug' => 'about-us']);

    expect(fn () => app(RebuildPaths::class)->execute(new RebuildPathsInput('content', [$this->tree->team->id], (string) $this->tree->author->id, under: $this->tree->about->id)))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('paths')->orderBy('path')->get()->all())->toEqual($before);
});

it('refuses IDs that aren\'t under the page, and writes nothing', function (): void {
    $before = DB::table('paths')->orderBy('path')->get()->all();
    DB::table('contents')->where('id', $this->tree->teamPost->id)->update(['slug' => 'elsewhere']);

    expect(fn () => app(RebuildPaths::class)->execute(new RebuildPathsInput('content', [$this->tree->team->id, $this->tree->teamPost->id], (string) $this->tree->editor->id, under: $this->tree->about->id)))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['ids'])->toBe(['Only the pages under the page can move with it.']));

    expect(DB::table('paths')->orderBy('path')->get()->all())->toEqual($before);
});

it('leaves RebuildPaths without under as it was', function (): void {
    // Without under, the abilities whose changes move addresses decide, as before: an editor manages terms, an author nothing.
    expect(fn () => app(RebuildPaths::class)->execute(new RebuildPathsInput('content', [$this->tree->team->id], (string) $this->tree->author->id)))
        ->toThrow(AuthorizationException::class);

    expect(app(RebuildPaths::class)->execute(new RebuildPathsInput('content', [$this->tree->team->id, $this->tree->teamPost->id], (string) $this->tree->editor->id)))->toBe(2);
});

it('recomputes every address with a kept page among them, keeping it', function (): void {
    // The post "Team" is at team; with About in the trash, Team would be there too.
    app(TrashContent::class)->execute(new ContentActionInput($this->tree->about->id, (string) $this->tree->editor->id));
    DB::table('paths')->where('target_id', $this->tree->team->id)->where('is_current', true)->update(['path' => 'about/team']);

    RecomputeAddresses::dispatch(JobActor::person((string) $this->tree->admin->id));

    expect(addrCurrent('content', $this->tree->team->id))->toBe('about/team')
        ->and(addrCurrent('content', $this->tree->teamPost->id))->toBe('team')
        ->and(addrCurrent('content', $this->tree->history->id))->toBe('history');
});

it('lets the importer\'s queued move run as the importer', function (): void {
    Role::findByName('author')->givePermissionTo('imports.run');
    $importer = $this->tree->author;
    PageTree::many($this->tree->about, 3, withAddresses: true);
    DB::table('contents')->where('id', $this->tree->about->id)->update(['slug' => 'about-us']);
    $jobId = app(StartJob::class)->execute(new StartJobInput(kind: SubtreeJobKind::KEY, label: 'Moving', runType: SubtreeJobKind::RUN_TYPE, runId: $this->tree->about->id, actorId: (string) $importer->id));
    $this->app->instance(Elevation::class, new DeniedElevation);

    app()->call([new MoveSubtreeAddresses($jobId, $this->tree->about->id, '', JobActor::person((string) $importer->id)), 'handle']);

    expect(queuedAt('about-us/'))->toBe(8)
        ->and(TrackedJob::query()->findOrFail($jobId)->state)->toBe(JobState::Finished);
});

it('fails with its words when its person may no longer edit the page, and Retry carries on', function (): void {
    Queue::fake([MoveSubtreeAddresses::class]);
    PageTree::many($this->tree->about, 60, withAddresses: true);
    addrRename($this->tree->about, 'about-us', $this->tree->editor);
    User::query()->findOrFail($this->tree->editor->id)->syncRoles(['author']);

    queuedStep();
    $job = queuedJob($this->tree->about->id);

    expect($job->state)->toBe(JobState::Failed)
        ->and($job->message)->toBe('Eddie Editor may no longer change “About”, so the pages under it stopped moving. Someone who may can retry.')
        ->and(queuedAt('about-us/'))->toBe(0);

    app(RetryJob::class)->execute(new RetryJobInput($job->id, (string) $this->tree->admin->id));
    queuedStep();

    expect(queuedAt('about-us/'))->toBe(65)
        ->and(queuedJob($this->tree->about->id)->state)->toBe(JobState::Finished);
});

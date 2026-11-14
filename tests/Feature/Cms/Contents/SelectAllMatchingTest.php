<?php

declare(strict_types=1);

use App\Domain\Contents\BulkRunJobKind;
use App\Domain\Contents\BulkRunWords;
use App\Domain\Contents\Jobs\AdvanceBulkRun;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\RecordBulkRunStepInput;
use App\Domain\Contents\Operations\Input\StartBulkRunInput;
use App\Domain\Contents\Operations\RecordBulkRunStep;
use App\Domain\Contents\Operations\StartBulkRun;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Jobs\JobKinds;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\RetryJob;
use App\Domain\Support\Security\JobActor;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Jobs\BulkRuns;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->admin = User::factory()->create(['name' => 'Ola'])->assignRole('administrator');
    $this->start = fn (User $person, array $filters = ['tab' => 'all'], string $action = 'trash', string $type = 'post'): array => app(StartBulkRun::class)->execute(new StartBulkRunInput($type, $action, $filters, (string) $person->id));
    $this->step = fn (string $runId, User $person): mixed => app()->call([new AdvanceBulkRun($runId, JobActor::person($person->id)), 'handle']);
    $this->job = fn (string $runId): object => DB::table('tracked_jobs')->where('run_id', $runId)->first();
    $this->trashed = fn (array $ids): int => DB::table('contents')->whereIn('id', $ids)->whereNotNull('deleted_at')->count();
});

it('starts a run over a list as one tracked job of its own kind, with the tab’s count as its total', function (): void {
    Queue::fake();
    BulkRuns::drafts($this->editor, 3);

    $started = ($this->start)($this->editor);
    $job = ($this->job)($started['runId']);
    $run = DB::table('bulk_runs')->where('id', $started['runId'])->first();

    expect($started['label'])->toBe('Move all posts to the trash')
        ->and($job->id)->toBe($started['jobId'])
        ->and($job->kind)->toBe('content.bulk')
        ->and($job->run_type)->toBe('content-bulk')
        ->and($job->state)->toBe('waiting')
        ->and($job->total)->toBe(3)
        ->and((int) $job->started_by)->toBe($this->editor->id)
        ->and($run->tracked_job_id)->toBe($job->id)
        ->and($run->action)->toBe('trash')
        ->and(json_decode($run->filters, true))->toEqual(['tab' => 'all']);

    Queue::assertPushed(AdvanceBulkRun::class, fn (AdvanceBulkRun $step): bool => $step->runId === $started['runId'] && $step->actor->userId === (string) $this->editor->id);
});

it('queues its first step only once the run has committed', function (): void {
    Queue::fake();
    BulkRuns::drafts($this->editor, 1);

    DB::transaction(function (): void {
        ($this->start)($this->editor);
        Queue::assertNothingPushed();
    });

    Queue::assertPushed(AdvanceBulkRun::class, 1);
});

it('names a run by its action, the list’s tab and whether a search or a filter narrows it', function (string $action, array $filters, string $label): void {
    Queue::fake();

    expect(($this->start)($this->editor, $filters, $action)['label'])->toBe($label);
})->with([
    'everything' => ['trash', ['tab' => 'all'], 'Move all posts to the trash'],
    'their own' => ['trash', ['tab' => 'mine'], 'Move all your posts to the trash'],
    'one tab' => ['trash', ['tab' => 'drafts'], 'Move all posts in the Drafts tab to the trash'],
    'a search' => ['trash', ['tab' => 'all', 'search' => 'harbour'], 'Move matching posts to the trash'],
    'the trash, restored' => ['restore', ['tab' => 'trash'], 'Restore all posts from the trash'],
    'the trash, searched and deleted' => ['delete', ['tab' => 'trash', 'search' => 'harbour'], 'Delete matching posts in the trash permanently'],
]);

it('gives the job no total under a search or a filter, as the list shows none there (I-140)', function (): void {
    Queue::fake();
    BulkRuns::drafts($this->editor, 3);

    $started = ($this->start)($this->editor, ['tab' => 'all', 'search' => 'Draft']);

    expect(($this->job)($started['runId'])->total)->toBeNull();
});

it('refuses an action the list’s tab can’t take, in words, writing nothing', function (string $action, string $tab, string $words): void {
    Queue::fake();

    expect(fn () => ($this->start)($this->editor, ['tab' => $tab], $action))->toThrow(ValidationException::class, $words)
        ->and(DB::table('tracked_jobs')->count())->toBe(0)
        ->and(DB::table('bulk_runs')->count())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'moving the trash to the trash' => ['trash', 'trash', 'These are in the trash already.'],
    'restoring outside the trash' => ['restore', 'all', 'Only items in the trash can be restored or deleted permanently.'],
]);

it('writes nothing when authorization fails', function (): void {
    Queue::fake();
    $nobody = User::factory()->create();

    expect(fn () => ($this->start)($nobody))->toThrow(AuthorizationException::class)
        ->and(DB::table('tracked_jobs')->count())->toBe(0)
        ->and(DB::table('bulk_runs')->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('moves every matching item to the trash, one Operation each, and tells the person in their inbox', function (): void {
    $ids = BulkRuns::drafts($this->editor, 3);

    $started = ($this->start)($this->editor);
    $job = ($this->job)($started['runId']);

    expect(($this->trashed)($ids))->toBe(3)
        ->and($job->state)->toBe('finished')
        ->and($job->done)->toBe(3)
        ->and($job->message)->toBe('Moved 3 posts to the trash.')
        ->and(json_decode((string) DB::table('inbox_items')->where('user_id', $this->editor->id)->value('body'), true)['title'])->toBe('Finished: Move all posts to the trash');
});

it('walks a list longer than one step, a hundred rows at a time', function (): void {
    Queue::fake();
    $ids = BulkRuns::drafts($this->editor, 101);
    $started = ($this->start)($this->editor);

    ($this->step)($started['runId'], $this->editor);

    expect(($this->trashed)($ids))->toBe(100)
        ->and(($this->job)($started['runId'])->state)->toBe('running')
        ->and(($this->job)($started['runId'])->done)->toBe(100);

    Queue::assertPushed(AdvanceBulkRun::class, 2);

    ($this->step)($started['runId'], $this->editor);

    expect(($this->trashed)($ids))->toBe(101)
        ->and(($this->job)($started['runId'])->state)->toBe('finished')
        ->and(($this->job)($started['runId'])->message)->toBe('Moved 101 posts to the trash.');
});

it('skips what the person may not change, counts it, and still moves the rest', function (): void {
    $theirs = BulkRuns::drafts($this->editor, 1);
    $mine = BulkRuns::drafts($this->author, 2);

    $started = ($this->start)($this->author);

    expect(($this->trashed)($mine))->toBe(2)
        ->and(($this->trashed)($theirs))->toBe(0)
        ->and(($this->job)($started['runId'])->message)->toBe('Moved 2 posts to the trash. Skipped 1 you may not change.');
});

it('reports an item the Operation refuses, in the Operation’s own words', function (): void {
    $home = addrPublished($this->admin, 'Welcome', 'page');
    $news = addrPublished($this->admin, 'News', 'page');
    addrChange($this->admin, ['frontShows' => 'page', 'frontPageId' => $home->id, 'postsPageId' => $news->id]);
    $about = addrPublished($this->admin, 'About', 'page');

    $started = ($this->start)($this->admin, ['tab' => 'all'], 'trash', 'page');
    $message = (string) ($this->job)($started['runId'])->message;

    expect(($this->trashed)([$about->id]))->toBe(1)
        ->and(($this->trashed)([$home->id, $news->id]))->toBe(0)
        ->and($message)->toStartWith('Moved 1 page to the trash. Skipped 2 that were refused: ')
        ->and($message)->toEndWith('Choose another in Settings › Reading first.');
});

it('says it moved none when every item was skipped', function (): void {
    BulkRuns::drafts($this->editor, 2);

    $started = ($this->start)($this->author);

    expect(($this->job)($started['runId'])->message)->toBe('No posts were moved to the trash. Skipped 2 you may not change.');
});

it('restores every matching item from the trash', function (): void {
    $ids = BulkRuns::drafts($this->editor, 2);
    ($this->start)($this->editor);

    $started = ($this->start)($this->editor, ['tab' => 'trash'], 'restore');

    expect(($this->trashed)($ids))->toBe(0)
        ->and(($this->job)($started['runId'])->message)->toBe('Restored 2 posts.');
});

it('deletes every matching item in the trash permanently', function (): void {
    $ids = BulkRuns::drafts($this->editor, 2);
    ($this->start)($this->editor);

    $started = ($this->start)($this->editor, ['tab' => 'trash'], 'delete');

    expect(DB::table('contents')->whereIn('id', $ids)->count())->toBe(0)
        ->and(($this->job)($started['runId'])->message)->toBe('Deleted 2 posts permanently.');
});

it('acts on nothing more once its job is cancelled', function (): void {
    Queue::fake();
    $ids = BulkRuns::drafts($this->editor, 3);
    $started = ($this->start)($this->editor);

    app(CancelJob::class)->execute(new CancelJobInput($started['jobId'], (string) $this->editor->id));
    ($this->step)($started['runId'], $this->editor);

    expect(($this->trashed)($ids))->toBe(0)
        ->and(DB::table('bulk_runs')->where('id', $started['runId'])->value('after'))->toBeNull()
        ->and(($this->job)($started['runId'])->state)->toBe('cancelled');

    Queue::assertPushed(AdvanceBulkRun::class, 1);
});

it('leaves alone, uncounted, whatever someone changed or added after the run started, in any order', function (string $action, array $filters, string $closing): void {
    Queue::fake();
    $ids = BulkRuns::drafts($this->editor, 101);
    $editorId = (string) $this->editor->id;

    if ($action === 'delete') {
        foreach ($ids as $id) {
            app(TrashContent::class)->execute(new ContentActionInput($id, $editorId));
        }
    }

    $started = ($this->start)($this->editor, $filters, $action);
    ($this->step)($started['runId'], $this->editor);

    // Some seconds after the person confirmed (timestamps keep whole seconds): the one item the
    // first step didn't reach is saved again, and a new one arrives.
    $this->travel(5)->seconds();
    $left = DB::table('contents')->whereIn('id', $ids)->when($action === 'trash', fn ($rows) => $rows->whereNull('deleted_at'))->value('id');
    DB::table('contents')->where('id', $left)->update(['updated_at' => now()]);
    $late = lifecycleDraft($this->editor, 'Draft 999');

    if ($action === 'delete') {
        app(TrashContent::class)->execute(new ContentActionInput($late->id, $editorId));
    }

    ($this->step)($started['runId'], $this->editor);
    $job = ($this->job)($started['runId']);

    expect(DB::table('contents')->where('id', $left)->exists())->toBeTrue()
        ->and(DB::table('contents')->where('id', $late->id)->exists())->toBeTrue()
        // Moving left both out of the trash; deleting left both in it.
        ->and(($this->trashed)([$left, $late->id]))->toBe($action === 'trash' ? 0 : 2)
        ->and($job->state)->toBe('finished')
        ->and($job->message)->toBe($closing);
})->with([
    'the newest first' => ['trash', ['tab' => 'all'], 'Moved 100 posts to the trash.'],
    'by title, an ascending order' => ['trash', ['tab' => 'all', 'sort' => 'title'], 'Moved 100 posts to the trash.'],
    'the trash, oldest first' => ['delete', ['tab' => 'trash', 'direction' => 'asc'], 'Deleted 100 posts permanently.'],
]);

it('carries on from where it stopped when retried, as whoever started it, not whoever asked', function (): void {
    Queue::fake();
    $ids = BulkRuns::drafts($this->author, 101);
    $started = ($this->start)($this->author);
    ($this->step)($started['runId'], $this->author);
    app(FailJob::class)->execute(new FailJobInput($started['jobId'], 'Teme stopped after 100 posts because of a problem on the server.', (string) $this->author->id));

    app(RetryJob::class)->execute(new RetryJobInput($started['jobId'], (string) $this->admin->id));

    Queue::assertPushed(AdvanceBulkRun::class, 3);
    expect(Queue::pushed(AdvanceBulkRun::class)->every(fn (AdvanceBulkRun $step): bool => $step->actor->userId === (string) $this->author->id))->toBeTrue();

    ($this->step)($started['runId'], $this->author);
    $job = ($this->job)($started['runId']);

    expect(($this->trashed)($ids))->toBe(101)
        ->and($job->state)->toBe('finished')
        ->and($job->done)->toBeLessThanOrEqual($job->total)
        ->and($job->message)->toBe('Moved 101 posts to the trash.');
});

it('refuses to carry on a run whose person’s account is gone, in words, and leaves its job failed', function (): void {
    Queue::fake();
    $started = ($this->start)($this->author);
    app(FailJob::class)->execute(new FailJobInput($started['jobId'], 'Teme stopped before any posts were handled, because of a problem on the server.', (string) $this->author->id));
    $this->author->delete();

    expect(fn () => app(RetryJob::class)->execute(new RetryJobInput($started['jobId'], (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'The person who started this no longer has an account, so it can’t carry on.')
        ->and(DB::table('tracked_jobs')->where('id', $started['jobId'])->value('state'))->toBe('failed');
});

it('says how far it got when a step fails for good, and tells the person', function (): void {
    Queue::fake();
    BulkRuns::drafts($this->editor, 101);
    $started = ($this->start)($this->editor);
    ($this->step)($started['runId'], $this->editor);

    (new AdvanceBulkRun($started['runId'], JobActor::person($this->editor->id)))->failed(new RuntimeException('The database went away.'));

    $job = ($this->job)($started['runId']);
    $item = json_decode((string) DB::table('inbox_items')->where('user_id', $this->editor->id)->value('body'), true);

    expect($job->state)->toBe('failed')
        ->and($job->message)->toBe('Teme stopped after 100 posts because of a problem on the server.')
        ->and($item['title'])->toBe('Failed: Move all posts to the trash');
});

it('records a step only for whoever started the run, writing nothing otherwise', function (): void {
    $run = BulkRuns::run($this->editor);
    $before = (array) DB::table('bulk_runs')->where('id', $run)->first();

    expect(fn () => app(RecordBulkRunStep::class)->execute(new RecordBulkRunStepInput($run, null, 'cursor', 100, ['not-allowed' => 3], null, (string) $this->author->id)))
        ->toThrow(AuthorizationException::class)
        ->and((array) DB::table('bulk_runs')->where('id', $run)->first())->toBe($before);
});

it('adds each step’s rows and skips to the run, keeping the first refusal’s words and the last cursor', function (): void {
    $run = BulkRuns::run($this->editor);
    $record = fn (?string $from, ?string $after, int $handled, array $skipped, ?string $refusal): bool => app(RecordBulkRunStep::class)->execute(new RecordBulkRunStepInput($run, $from, $after, $handled, $skipped, $refusal, (string) $this->editor->id));

    expect($record(null, 'first', 100, ['not-allowed' => 2, 'refused' => 1], 'This is your home page. Choose another in Settings › Reading first.'))->toBeTrue()
        ->and($record('first', null, 40, ['refused' => 1, 'gone' => 1], 'This is your posts page. Choose another in Settings › Reading first.'))->toBeTrue();

    $row = DB::table('bulk_runs')->where('id', $run)->first();

    expect($row->done)->toBe(140)
        ->and($row->after)->toBe('first')
        ->and(json_decode($row->skipped, true))->toEqual(['not-allowed' => 2, 'refused' => 2, 'gone' => 1])
        ->and($row->refusal)->toBe('This is your home page. Choose another in Settings › Reading first.');
});

it('records nothing, and says so, when another step has already moved the run on from where this one read', function (): void {
    $run = BulkRuns::run($this->editor, ['after' => 'second', 'done' => 100]);
    $before = (array) DB::table('bulk_runs')->where('id', $run)->first();

    $recorded = app(RecordBulkRunStep::class)->execute(new RecordBulkRunStepInput($run, 'first', 'second', 100, ['gone' => 1], null, (string) $this->editor->id));

    expect($recorded)->toBeFalse()
        ->and((array) DB::table('bulk_runs')->where('id', $run)->first())->toBe($before);
});

it('words the skips of several kinds in one sentence, and the count so far', function (): void {
    $posts = app(ContentTypeRegistry::class)->get('post');
    $words = app(BulkRunWords::class);

    expect($words->closing($posts, 'trash', 1180, ['not-allowed' => 20, 'gone' => 1, 'refused' => 1], 'This is your home page. Choose another in Settings › Reading first.'))
        ->toBe('Moved 1,180 posts to the trash. Skipped 22: 20 you may not change, 1 that no longer exists and 1 refused: This is your home page. Choose another in Settings › Reading first.')
        ->and($words->closing($posts, 'restore', 1, ['gone' => 3], null))->toBe('Restored 1 post. Skipped 3 that no longer exist.')
        ->and($words->closing($posts, 'delete', 0, ['not-allowed' => 1, 'gone' => 1], null))->toBe('No posts were deleted. Skipped 2: 1 you may not change and 1 that no longer exists.')
        ->and($words->soFar(0))->toBeNull()
        ->and($words->soFar(1))->toBe('Skipped 1 so far.')
        ->and($words->soFar(1204))->toBe('Skipped 1,204 so far.');
});

it('tags its kind, so Tools › Jobs and the header offer Retry on a failed run', function (): void {
    expect(app(JobKinds::class)->find('content.bulk'))->toBeInstanceOf(BulkRunJobKind::class);
});

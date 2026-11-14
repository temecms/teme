<?php

declare(strict_types=1);

use App\Domain\Inbox\Models\InboxItem;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Jobs\JobsFixtures;

uses(RefreshDatabase::class);

it('refuses a job the table’s rules forbid', function (string $case): void {
    $ada = User::factory()->create();

    // Each refusal names its own check, so a missing table never passes for one.
    [$changes, $check] = match ($case) {
        'an unknown state' => [['state' => 'paused'], 'tracked_jobs_state_check'],
        'an ended job with no end time' => [['state' => 'finished', 'ended_at' => null], 'tracked_jobs_ended_check'],
        'a running job with an end time' => [['state' => 'running', 'ended_at' => now()], 'tracked_jobs_ended_check'],
        'more done than the total' => [['total' => 10, 'done' => 11], 'tracked_jobs_progress_check'],
        'less than nothing done' => [['done' => -1], 'tracked_jobs_progress_check'],
        'the operator’s job naming a person' => [['starter' => 'operator', 'started_by' => $ada->id], 'tracked_jobs_starter_check'],
        'a run with a type and no ID' => [['run_type' => 'import', 'run_id' => null], 'tracked_jobs_run_check'],
    };

    expect(fn () => JobsFixtures::job($changes))->toThrow(QueryException::class, $check);
})->with([
    'an unknown state',
    'an ended job with no end time',
    'a running job with an end time',
    'more done than the total',
    'less than nothing done',
    'the operator’s job naming a person',
    'a run with a type and no ID',
]);

it('keeps a job in each of its five states, the ended ones with their end time', function (): void {
    $ada = User::factory()->create();

    JobsFixtures::job(['state' => 'waiting', 'done' => 0, 'total' => null]);
    JobsFixtures::job(['state' => 'running', 'starter' => 'person', 'started_by' => $ada->id, 'run_type' => 'import', 'run_id' => '01k9zq5s1d4x2m7n8p0r3t6v9w']);

    foreach (['finished', 'failed', 'cancelled'] as $state) {
        JobsFixtures::job(['state' => $state, 'ended_at' => now()]);
    }

    expect(TrackedJob::query()->get()->map(fn (TrackedJob $job): string => $job->state->value)->sort()->values()->all())
        ->toBe(['cancelled', 'failed', 'finished', 'running', 'waiting'])
        ->and(TrackedJob::query()->where('state', 'failed')->firstOrFail()->state)->toBe(JobState::Failed)
        ->and(JobState::Failed->ended())->toBeTrue()
        ->and(JobState::Running->ended())->toBeFalse()
        ->and(array_map(fn (JobState $state): string => $state->label(), JobState::cases()))
        ->toBe(['Waiting', 'Running', 'Finished', 'Failed', 'Cancelled']);
});

it('refuses an inbox item whose body isn’t an object with a title in words (rule 4)', function (string $case): void {
    $ada = User::factory()->create();

    $body = match ($case) {
        'a list' => '[]',
        'an object with no title' => '{"text": "1,204 posts came in."}',
        'a title that isn’t words' => '{"title": 3}',
    };

    expect(fn () => JobsFixtures::inboxItem($ada->id, ['body' => $body]))->toThrow(QueryException::class, 'inbox_items_body_check');
})->with(['a list', 'an object with no title', 'a title that isn’t words']);

it('reads an inbox item’s body back as it was written', function (): void {
    $ada = User::factory()->create();
    $body = ['title' => 'Finished: Import from WordPress', 'text' => null, 'link' => ['route' => 'tools.page-cache.show', 'parameters' => [], 'ability' => 'page-cache.clear']];

    $id = JobsFixtures::inboxItem($ada->id, ['body' => $body]);

    expect(InboxItem::query()->findOrFail($id)->body)->toEqual($body);
});

it('takes a person’s inbox with them, and keeps the jobs they started naming no one', function (): void {
    $ada = User::factory()->create();
    JobsFixtures::inboxItem($ada->id);
    $job = JobsFixtures::job(['starter' => 'person', 'started_by' => $ada->id]);

    $ada->delete();

    expect(DB::table('inbox_items')->count())->toBe(0)
        ->and(DB::table('tracked_jobs')->where('id', $job)->first(['starter', 'started_by']))
        ->toEqual((object) ['starter' => 'person', 'started_by' => null]);
});

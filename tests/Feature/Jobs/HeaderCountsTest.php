<?php

declare(strict_types=1);

use App\Domain\Jobs\Queries\TrackedJobList;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Jobs\HeaderFixtures;
use Tests\Fixtures\Jobs\JobsFixtures;
use Tests\Fixtures\Jobs\RecordingJobKind;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    RecordingJobKind::register();
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->other = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
    $this->counts = fn (User $person): mixed => $this->actingAs($person)->get(route('dashboard'))->assertOk()->viewData('page')['props']['headerCounts'];
});

it('counts a person’s waiting and running jobs, and their failed ones whose kind is installed', function (): void {
    HeaderFixtures::jobOf($this->author, ['state' => 'waiting', 'done' => 0]);
    HeaderFixtures::jobOf($this->author);
    HeaderFixtures::jobOf($this->author, ['state' => 'failed', 'ended_at' => now()]);
    HeaderFixtures::jobOf($this->author, ['state' => 'failed', 'ended_at' => now()]);
    HeaderFixtures::jobOf($this->author, ['state' => 'failed', 'ended_at' => now(), 'kind' => 'gone']);
    HeaderFixtures::jobOf($this->author, ['state' => 'finished', 'done' => 400, 'ended_at' => now()]);
    HeaderFixtures::jobOf($this->other);
    JobsFixtures::job();

    expect(app(TrackedJobList::class)->countsFor($this->author->id))->toBe(['running' => 2, 'failed' => 2, 'hasJobs' => true]);
});

it('tells the header that someone who never started a job has none to show', function (): void {
    HeaderFixtures::jobOf($this->other);

    expect(app(TrackedJobList::class)->countsFor($this->author->id))->toBe(['running' => 0, 'failed' => 0, 'hasJobs' => false])
        ->and(($this->counts)($this->author))->toBe(['running' => 0, 'failed' => 0, 'hasJobs' => false, 'unread' => 0]);
});

it('stops each count at 100, as the inbox’s unread count stops', function (): void {
    foreach (range(1, 101) as $n) {
        HeaderFixtures::jobOf($this->author, ['created_at' => now()->subMinutes($n)]);
    }

    expect(app(TrackedJobList::class)->countsFor($this->author->id)['running'])->toBe(100);
});

it('reads a person’s counts through the index on who started each job', function (): void {
    foreach (range(1, 30) as $n) {
        HeaderFixtures::jobOf($this->other, ['created_at' => now()->subMinutes($n)]);
    }

    HeaderFixtures::jobOf($this->author);

    $plan = DB::transaction(function (): string {
        DB::statement('SET LOCAL enable_seqscan = off');
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TrackedJobList::class)->countsFor($this->author->id);
        $read = DB::getQueryLog()[0];
        DB::disableQueryLog();

        return collect(DB::select('EXPLAIN '.$read['query'], $read['bindings']))->pluck('QUERY PLAN')->implode("\n");
    });

    expect($plan)->toContain('tracked_jobs_started_by_index')
        ->not->toContain('Seq Scan on tracked_jobs');
});

it('lists a person’s own latest jobs, newest first, and never anyone else’s', function (): void {
    foreach (range(1, 12) as $n) {
        HeaderFixtures::jobOf($this->author, ['label' => "Job {$n}", 'created_at' => now()->subMinutes($n)]);
    }

    HeaderFixtures::jobOf($this->other, ['label' => 'Someone else’s']);

    $rows = app(TrackedJobList::class)->startedBy($this->author->id, 10);

    expect(array_column($rows, 'label'))->toBe(['Job 1', 'Job 2', 'Job 3', 'Job 4', 'Job 5', 'Job 6', 'Job 7', 'Job 8', 'Job 9', 'Job 10'])
        ->and($rows[0]['startedBy'])->toBe(['kind' => 'person', 'name' => 'Ada'])
        ->and($rows[0]['canCancel'])->toBeTrue();
});

it('shares the counts with every screen for a signed-in, verified person', function (): void {
    HeaderFixtures::jobOf($this->author);
    JobsFixtures::inboxItem($this->author->id);
    JobsFixtures::inboxItem($this->author->id);
    JobsFixtures::inboxItem($this->author->id, ['read_at' => now()]);

    expect(($this->counts)($this->author))->toBe(['running' => 1, 'failed' => 0, 'hasJobs' => true, 'unread' => 2]);
});

it('counts the unread inbox items up to 100, as the inbox does', function (): void {
    foreach (range(1, 101) as $n) {
        JobsFixtures::inboxItem($this->author->id);
    }

    expect(($this->counts)($this->author)['unread'])->toBe(100);
});

it('gives no counts to someone whose email isn’t verified', function (): void {
    $waiting = HeaderFixtures::unverified();
    HeaderFixtures::jobOf($waiting);

    expect($this->actingAs($waiting)->get(route('profile.edit'))->assertOk()->viewData('page')['props']['headerCounts'])->toBeNull();
});

it('leaves the counts out for a visitor who isn’t signed in', function (): void {
    expect($this->get(route('login'))->assertOk()->viewData('page')['props']['headerCounts'])->toBeNull();
});

it('leaves the counts out of a partial reload that doesn’t ask for them', function (): void {
    HeaderFixtures::jobOf($this->author);

    $this->actingAs($this->author)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('headerCounts.running', 1)
            ->reloadOnly('statusBanner', fn (Assert $reload) => $reload->missing('headerCounts')));
});

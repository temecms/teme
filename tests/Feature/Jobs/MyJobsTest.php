<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Jobs\HeaderFixtures;
use Tests\Fixtures\Jobs\JobsFixtures;
use Tests\Fixtures\Jobs\RecordingJobKind;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->kind = RecordingJobKind::register();
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->other = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
    $this->admin = User::factory()->create(['name' => 'Ola'])->assignRole('administrator');
});

it('answers a person’s own latest jobs, their counts and their unread items as JSON', function (): void {
    $older = HeaderFixtures::jobOf($this->author, ['state' => 'failed', 'ended_at' => now(), 'created_at' => now()->subHour()]);
    $newer = HeaderFixtures::jobOf($this->author);
    JobsFixtures::inboxItem($this->author->id);

    $this->actingAs($this->author)->getJson(route('my-jobs.index'))
        ->assertOk()
        ->assertJsonStructure(['rows', 'running', 'failed', 'hasJobs', 'unread', 'siteTimezone', 'toolsJobsHref'])
        ->assertJsonPath('rows.0.id', $newer)
        ->assertJsonPath('rows.0.percent', 47)
        ->assertJsonPath('rows.0.canCancel', true)
        ->assertJsonPath('rows.1.id', $older)
        ->assertJsonPath('rows.1.canRetry', true)
        ->assertJsonPath('running', 1)
        ->assertJsonPath('failed', 1)
        ->assertJsonPath('hasJobs', true)
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('toolsJobsHref', null);
});

it('gives someone who looks after jobs the way to Tools › Jobs', function (): void {
    $this->actingAs($this->admin)->getJson(route('my-jobs.index'))
        ->assertOk()
        ->assertJsonPath('toolsJobsHref', '/admin/tools/jobs')
        ->assertJsonPath('rows', []);
});

it('answers with ten jobs at most, and never anyone else’s', function (): void {
    foreach (range(1, 11) as $n) {
        HeaderFixtures::jobOf($this->author, ['created_at' => now()->subMinutes($n)]);
    }

    $theirs = HeaderFixtures::jobOf($this->other);

    $rows = $this->actingAs($this->author)->getJson(route('my-jobs.index'))->assertOk()->json('rows');

    expect($rows)->toHaveCount(10)
        ->and(array_column($rows, 'id'))->not->toContain($theirs);
});

it('retries a person’s own failed job from the header, as Tools › Jobs does', function (): void {
    $job = HeaderFixtures::jobOf($this->author, ['state' => 'failed', 'ended_at' => now()]);

    $this->actingAs($this->author)->from('/admin/posts')->post(route('my-jobs.retry', ['job' => $job]))
        ->assertRedirect('/admin/posts')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Retrying Import from WordPress. It carries on from where it stopped.']);

    expect(DB::table('tracked_jobs')->where('id', $job)->value('state'))->toBe('waiting')
        ->and($this->kind->retried)->toBe([['job' => $job, 'by' => (string) $this->author->id]]);
});

it('cancels a person’s own running job from the header', function (): void {
    $job = HeaderFixtures::jobOf($this->author);

    $this->actingAs($this->author)->from('/admin/posts')->post(route('my-jobs.cancel', ['job' => $job]))
        ->assertRedirect('/admin/posts')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Cancelled Import from WordPress. What it did stays done.']);

    expect(DB::table('tracked_jobs')->where('id', $job)->value('state'))->toBe('cancelled')
        ->and($this->kind->cancelled)->toBe([['job' => $job, 'by' => (string) $this->author->id]]);
});

it('refuses to retry or cancel someone else’s job from the header, changing nothing', function (string $route, string $state): void {
    $job = HeaderFixtures::jobOf($this->other, ['state' => $state, 'ended_at' => $state === 'failed' ? now() : null]);

    $this->actingAs($this->author)->post(route($route, ['job' => $job]))->assertForbidden();

    expect(DB::table('tracked_jobs')->where('id', $job)->value('state'))->toBe($state)
        ->and($this->kind->retried)->toBe([])
        ->and($this->kind->cancelled)->toBe([])
        ->and(DB::table('inbox_items')->count())->toBe(0);
})->with([
    'retrying' => ['my-jobs.retry', 'failed'],
    'cancelling' => ['my-jobs.cancel', 'running'],
]);

it('says in words why a job can’t be retried or cancelled', function (string $route, array $changes, string $words): void {
    $job = HeaderFixtures::jobOf($this->author, $changes);

    $this->actingAs($this->author)->from('/admin/posts')->post(route($route, ['job' => $job]))
        ->assertRedirect('/admin/posts')
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => $words]);
})->with([
    'a running job retried' => ['my-jobs.retry', [], 'This job isn’t failed, so there’s nothing to retry.'],
    'a finished job cancelled' => ['my-jobs.cancel', ['state' => 'finished', 'done' => 400, 'ended_at' => now()], 'This job has already ended, so there’s nothing to cancel.'],
]);

it('answers the latest ten inbox items and the unread count as JSON', function (): void {
    foreach (range(1, 12) as $n) {
        JobsFixtures::inboxItem($this->author->id, ['body' => ['title' => "Item {$n}", 'text' => null, 'link' => null], 'created_at' => now()->subMinutes($n)]);
    }

    $answer = $this->actingAs($this->author)->getJson(route('inbox.latest'))->assertOk();

    expect($answer->json('rows'))->toHaveCount(10)
        ->and($answer->json('rows.0.title'))->toBe('Item 1')
        ->and($answer->json('unread'))->toBe(12)
        ->and($answer->json('inboxHref'))->toBe('/admin/inbox');
});

it('never answers with someone else’s inbox items', function (): void {
    JobsFixtures::inboxItem($this->other->id);

    $this->actingAs($this->author)->getJson(route('inbox.latest'))
        ->assertOk()
        ->assertJsonPath('rows', [])
        ->assertJsonPath('unread', 0);
});

it('keeps both answers from someone whose email isn’t verified', function (): void {
    $waiting = HeaderFixtures::unverified();

    $this->actingAs($waiting)->getJson(route('my-jobs.index'))->assertForbidden();
    $this->actingAs($waiting)->getJson(route('inbox.latest'))->assertForbidden();
});

it('refuses an address that isn’t a job', function (): void {
    $this->actingAs($this->author)->post('/admin/my-jobs/not-a-job/retry')->assertMethodNotAllowed();
});

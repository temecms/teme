<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Jobs\JobsFixtures;
use Tests\Fixtures\Jobs\RecordingJobKind;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->kind = RecordingJobKind::register();
    $this->post = fn (string $address) => $this->actingAs($this->admin)->from('/admin/tools/jobs')->post($address);
});

it('retries a failed job from Tools › Jobs, and says so', function (): void {
    $id = JobsFixtures::job(['state' => 'failed', 'ended_at' => now()]);

    ($this->post)("/admin/tools/jobs/{$id}/retry")
        ->assertRedirect('/admin/tools/jobs')
        ->assertInertiaFlash('toast.message', 'Retrying Import from WordPress. It carries on from where it stopped.');

    expect(DB::table('tracked_jobs')->where('id', $id)->value('state'))->toBe('waiting')
        ->and($this->kind->retried)->toHaveCount(1);
});

it('cancels a running job from Tools › Jobs, and says so', function (): void {
    $id = JobsFixtures::job();

    ($this->post)("/admin/tools/jobs/{$id}/cancel")
        ->assertRedirect('/admin/tools/jobs')
        ->assertInertiaFlash('toast.message', 'Cancelled Import from WordPress. What it did stays done.');

    expect(DB::table('tracked_jobs')->where('id', $id)->value('state'))->toBe('cancelled');
});

it('says why a retry or a cancel was refused, and changes nothing', function (string $action): void {
    $id = JobsFixtures::job(['state' => 'finished', 'done' => 400, 'ended_at' => now()]);

    ($this->post)("/admin/tools/jobs/{$id}/{$action}")
        ->assertRedirect('/admin/tools/jobs')
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', $action === 'retry' ? 'This job isn’t failed, so there’s nothing to retry.' : 'This job has already ended, so there’s nothing to cancel.');

    expect(DB::table('tracked_jobs')->where('id', $id)->value('state'))->toBe('finished');
})->with(['retry', 'cancel']);

it('retries every failed job it can, and says how many', function (): void {
    foreach (range(1, 3) as $minutes) {
        JobsFixtures::job(['state' => 'failed', 'ended_at' => now(), 'created_at' => now()->subMinutes($minutes)]);
    }

    $gone = JobsFixtures::job(['kind' => 'gone', 'state' => 'failed', 'ended_at' => now()]);

    ($this->post)('/admin/tools/jobs/retry-failed')
        ->assertRedirect('/admin/tools/jobs')
        ->assertInertiaFlash('toast.message', 'Retrying 3 failed jobs.');

    expect($this->kind->retried)->toHaveCount(3)
        ->and(DB::table('tracked_jobs')->where('id', $gone)->value('state'))->toBe('failed');
});

it('says so when there’s no failed job to retry', function (): void {
    JobsFixtures::job();

    ($this->post)('/admin/tools/jobs/retry-failed')
        ->assertInertiaFlash('toast.type', 'info')
        ->assertInertiaFlash('toast.message', 'No failed jobs to retry.');
});

it('refuses the actions to everyone but those who hold jobs.manage, and changes nothing', function (): void {
    $id = JobsFixtures::job(['state' => 'failed', 'ended_at' => now()]);
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->post("/admin/tools/jobs/{$id}/retry")->assertForbidden();
    $this->actingAs($editor)->post('/admin/tools/jobs/retry-failed')->assertForbidden();

    expect(DB::table('tracked_jobs')->where('id', $id)->value('state'))->toBe('failed')
        ->and($this->kind->retried)->toBe([]);
});

it('answers a job that doesn’t exist with 404, and an address that isn’t a job’s with 405', function (): void {
    ($this->post)('/admin/tools/jobs/01k9zq5s1d4x2m7n8p0r3t6v9w/retry')->assertNotFound();
    ($this->post)('/admin/tools/jobs/NOT-A-JOB/cancel')->assertMethodNotAllowed();
});

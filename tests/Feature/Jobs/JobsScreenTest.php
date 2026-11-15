<?php

declare(strict_types=1);

use App\Domain\Jobs\Queries\TrackedJobList;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Jobs\JobsFixtures;
use Tests\Fixtures\Jobs\RecordingJobKind;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->grace = User::factory()->create(['name' => 'Grace Editor'])->assignRole('editor');
    RecordingJobKind::register();
});

it('shows an administrator every job, newest first, in the words Tools › Jobs draws (I-1714)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'UTC'));
    JobsFixtures::job(['label' => 'Match images', 'state' => 'cancelled', 'starter' => 'person', 'started_by' => null, 'total' => 12, 'done' => 4, 'message' => 'Cancelled by Ada Admin.', 'created_at' => now()->subHours(3), 'ended_at' => now()->subHours(2)]);
    JobsFixtures::job(['label' => 'Resize images', 'state' => 'failed', 'starter' => 'person', 'started_by' => $this->grace->id, 'total' => 3310, 'done' => 2987, 'message' => 'Stopped at a file that is missing from storage.', 'created_at' => now()->subHours(2), 'ended_at' => now()->subMinutes(30)]);
    JobsFixtures::job(['created_at' => now()->subHour()]);
    JobsFixtures::job(['label' => 'Rebuild the link index', 'state' => 'waiting', 'total' => null, 'done' => 0, 'message' => null]);

    $this->actingAs($this->admin)->get('/admin/tools/jobs')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tools/jobs')
            ->has('rows', 4)
            ->where('rows.0.label', 'Rebuild the link index')
            ->where('rows.0.state', 'waiting')
            ->where('rows.0.stateLabel', 'Waiting')
            ->where('rows.0.total', null)
            ->where('rows.0.percent', null)
            ->where('rows.0.startedBy', ['kind' => 'operator', 'name' => null])
            ->where('rows.0.canRetry', false)
            ->where('rows.0.canCancel', true)
            ->where('rows.0.endedAt', null)
            ->where('rows.1.label', 'Import from WordPress')
            ->where('rows.1.percent', 47)
            ->where('rows.1.message', 'Importing posts')
            ->where('rows.1.startedAt', '2026-11-24T11:00:00+00:00')
            ->where('rows.2.stateLabel', 'Failed')
            ->where('rows.2.percent', 90)
            ->where('rows.2.startedBy', ['kind' => 'person', 'name' => 'Grace Editor'])
            ->where('rows.2.canRetry', true)
            ->where('rows.2.canCancel', false)
            ->where('rows.3.startedBy', ['kind' => 'removed', 'name' => null])
            ->where('rows.3.canRetry', false)
            ->where('failed', 1)
            ->where('newer', null)
            ->where('older', null)
            ->where('inboxHref', '/admin/inbox'));
});

it('refuses Tools › Jobs to everyone but those who hold jobs.manage', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);

    $this->actingAs($person)->get('/admin/tools/jobs')->assertForbidden();
})->with(['editor', 'author', 'contributor']);

it('offers Retry only for a failed job whose kind is installed', function (): void {
    $installed = JobsFixtures::job(['state' => 'failed', 'ended_at' => now(), 'created_at' => now()->subMinute()]);
    $gone = JobsFixtures::job(['kind' => 'gone', 'state' => 'failed', 'ended_at' => now()]);

    $rows = collect(app(TrackedJobList::class)->page(null, null, 50)['rows'])->keyBy('id');

    expect($rows[$installed]['canRetry'])->toBeTrue()
        ->and($rows[$gone]['canRetry'])->toBeFalse()
        ->and($rows[$gone]['stateLabel'])->toBe('Failed')
        ->and(app(TrackedJobList::class)->failedCount())->toBe(1)
        ->and(app(TrackedJobList::class)->failedIds(100))->toBe([$installed]);
});

it('rounds the percentage down, so a job never shows 100% before it ends', function (): void {
    $nearly = JobsFixtures::job(['total' => 400, 'done' => 399]);
    $counted = JobsFixtures::job(['total' => 400, 'done' => 400]);

    $percent = array_column(app(TrackedJobList::class)->page(null, null, 50)['rows'], 'percent', 'id');

    expect($percent[$nearly])->toBe(99)
        ->and($percent[$counted])->toBe(99);
});

it('pages the jobs by the reader’s page size, with addresses for the pages either side (I-140)', function (): void {
    foreach (range(1, 30) as $minutes) {
        JobsFixtures::job(['created_at' => now()->subMinutes($minutes)]);
    }

    lscrPrefer($this->admin, ['lists.per_page' => 25]);

    $first = $this->actingAs($this->admin)->get('/admin/tools/jobs')->assertOk()->viewData('page')['props'];

    expect($first['rows'])->toHaveCount(25)
        ->and($first['newer'])->toBeNull()
        ->and($first['older'])->toStartWith('/admin/tools/jobs?after=');

    $second = $this->actingAs($this->admin)->get($first['older'])->assertOk()->viewData('page')['props'];

    expect($second['rows'])->toHaveCount(5)
        ->and($second['newer'])->toStartWith('/admin/tools/jobs?before=');
});

it('reads a run’s jobs, newest first, and no other run’s', function (): void {
    $older = JobsFixtures::job(['run_type' => 'import', 'run_id' => 'run-a', 'created_at' => now()->subHour()]);
    $newer = JobsFixtures::job(['run_type' => 'import', 'run_id' => 'run-a']);
    JobsFixtures::job(['run_type' => 'import', 'run_id' => 'run-b']);
    JobsFixtures::job();

    expect(array_column(app(TrackedJobList::class)->ofRun('import', 'run-a'), 'id'))->toBe([$newer, $older]);
});

it('can page Tools › Jobs and find its failed jobs through their own indexes', function (): void {
    DB::insert(
        "INSERT INTO tracked_jobs (id, kind, owner, label, starter, state, total, done, created_at, updated_at, ended_at) SELECT lpad(g::text, 26, '0'), 'fixture', 'core', 'Job ' || g, 'operator', CASE WHEN g % 20 = 0 THEN 'failed' ELSE 'finished' END, 10, 10, now() - g * interval '1 minute', now(), now() FROM generate_series(1, 3000) g",
    );
    DB::statement('ANALYZE tracked_jobs');
    // The plans that read every row, or sort what a bitmap found, are ruled out, so the plan left shows the indexes can serve both.
    DB::statement('SET LOCAL enable_bitmapscan = off');
    DB::statement('SET LOCAL enable_sort = off');

    $page = planOf(fn () => app(TrackedJobList::class)->page(null, null, 50), 'tracked_jobs');
    $failed = genericPlanOf(fn () => app(TrackedJobList::class)->failedIds(100), "state = 'failed'");

    expect($page)->toContain('tracked_jobs_created_index')->not->toContain('Seq Scan on tracked_jobs')->not->toContain('Sort')
        ->and($failed)->toContain('tracked_jobs_failed_index')->not->toContain('Seq Scan on tracked_jobs');
});

it('names the plugin beside a plugin-owned job', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    PluginFixtures::use('jobs');
    PluginFixtures::installed(['tests/jobs' => 'jobs']);
    $plugin = app(PluginRegistry::class)->find('tests/jobs');
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/jobs',
        $plugin?->manifest?->version() ?? '1.0.0',
        $plugin?->manifest?->capabilities() ?? [],
        $plugin?->manifest?->hosts() ?? [],
        $plugin?->manifest?->hooks() ?? [],
        null,
    ));

    $id = JobsFixtures::job([
        'kind' => 'plugin-job',
        'owner' => 'jobs',
        'label' => 'Write a line',
        'state' => 'waiting',
        'total' => null,
        'done' => 0,
        'message' => null,
    ]);

    $this->actingAs($this->admin)->get('/admin/tools/jobs')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tools/jobs')
            ->where('rows.0.id', $id)
            ->where('rows.0.plugin', 'Jobs')
            ->where('rows.0.owner', 'jobs'));
});

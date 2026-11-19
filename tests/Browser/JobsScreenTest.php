<?php

declare(strict_types=1);

use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Jobs\JobsFixtures;
use Tests\Fixtures\Jobs\RecordingJobKind;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    RecordingJobKind::register();
    $this->failed = JobsFixtures::job(['label' => 'Rebuild the link index', 'state' => 'failed', 'ended_at' => now(), 'message' => 'Stopped at a file that is missing from storage.', 'created_at' => now()->subHour()]);
    $this->running = JobsFixtures::job(['starter' => 'person', 'started_by' => $this->admin->id]);
    JobsFixtures::job(['label' => 'Recount media', 'state' => 'finished', 'total' => 1284, 'done' => 1284, 'ended_at' => now(), 'message' => 'Counted 3,310 images.', 'created_at' => now()->subDay()]);

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get(route('tools.jobs.index'))->assertOk();
});

it('passes axe on Tools › Jobs, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/tools/jobs?whose=everyone')
        ->assertSee('Imports, bulk changes and other long tasks.')
        ->assertSee('47% done')
        ->assertSee('Retry all failed (1)')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('retries a failed job and cancels a running one from Tools › Jobs', function (): void {
    cmSignIn($this->admin)
        ->navigate('/admin/tools/jobs?whose=everyone')
        ->click('[aria-label="Retry Rebuild the link index"]')
        ->assertSee('Retrying Rebuild the link index. It carries on from where it stopped.')
        ->click('[aria-label="Cancel Import from WordPress"]')
        ->assertSee('Cancelled Import from WordPress. What it did stays done.');

    expect(DB::table('tracked_jobs')->where('id', $this->failed)->value('state'))->toBe('waiting')
        ->and(DB::table('tracked_jobs')->where('id', $this->running)->value('state'))->toBe('cancelled');
});

it('draws a plugin mark beside a plugin-owned job, and passes axe in light and dark', function (string $scheme): void {
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
    JobsFixtures::job([
        'kind' => 'plugin-job',
        'owner' => 'jobs',
        'label' => 'Write a line',
        'state' => 'waiting',
        'total' => null,
        'done' => 0,
        'message' => null,
        'created_at' => now()->addMinute(),
    ]);

    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    cmSignIn($this->admin)
        ->navigate('/admin/tools/jobs?whose=everyone')
        ->assertSee('Write a line')
        ->assertSee('Jobs')
        ->assertPresent('[data-slot="plugin-mark"]')
        ->assertNoAccessibilityIssues(3);
})->with(['light', 'dark']);

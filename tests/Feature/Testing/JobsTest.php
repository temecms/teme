<?php

declare(strict_types=1);

use App\Domain\Jobs\Queries\TrackedJobList;
use App\Domain\Plugins\Jobs\PluginJobKind;
use App\Domain\Plugins\Seams\PluginServices;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Jobs\Jobs;
use Teme\Testing\InteractsWithTeme;
use Teme\Testing\JobRun;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class, InteractsWithTeme::class);

beforeEach(fn () => config(['teme.version' => PluginFixtures::TEME]));

afterEach(fn () => PluginFixtures::forget());

it('runs a job at once on the sync queue and answers it finished', function (): void {
    $run = $this->plugin(PluginFixtures::path('jobs'))->enable()->runJob('write-line', ['line' => 'one']);

    expect($run)->toBeInstanceOf(JobRun::class)
        ->and($run->state)->toBe(JobRun::FINISHED)
        ->and(DB::table('jobs_lines')->count())->toBe(1);
});

it('answers a job that throws on its last try as failed, with the first line of what it threw', function (): void {
    $run = $this->plugin(PluginFixtures::path('jobs'))->enable()->runJob('throw');

    expect($run->state)->toBe(JobRun::FAILED)
        ->and($run->message)->toBe('The workspace answered nothing.');
});

it('lets a job’s throw on the sync queue reach the code that queued it, once the run is marked failed', function (): void {
    $admin = $this->person('administrator');
    $this->plugin(PluginFixtures::path('jobs'))->enable($admin);
    $this->actingAs(User::query()->findOrFail($admin->id));
    $jobs = app(PluginServices::class)->for('tests/jobs', 'jobs', ['jobs'], [])[Jobs::class];

    expect(fn () => $jobs->queue('throw'))->toThrow(RuntimeException::class, 'The workspace answered nothing.');
    expect(app(TrackedJobList::class)->ofRun(PluginJobKind::KEY, 'throw')[0]['state'])->toBe('failed');
});

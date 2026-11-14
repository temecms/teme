<?php

declare(strict_types=1);

use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\Operations\Input\SettleRestoredSiteInput;
use App\Domain\Backups\Operations\SettleRestoredSite;
use App\Domain\Inbox\Models\InboxItem;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Jobs\JobsFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'UTC'));
    $source = BackupRecords::run(['state' => BackupRunState::Running, 'started_at' => now()->subMinutes(10), 'ended_at' => null]);
    $this->settle = fn (): string => (string) app(SettleRestoredSite::class)->execute(new SettleRestoredSiteInput(
        $source->id, null, CarbonImmutable::now()->subMinutes(5), CarbonImmutable::now(), 'This server', null, null,
    ));
});

it('finishes the backup job the site was restored from, ends every other job the old server left open in words that fit, and tells no one', function (): void {
    $person = User::factory()->create();
    $backup = JobsFixtures::job([
        'kind' => 'backup', 'label' => 'Nightly backup', 'run_type' => 'backup', 'run_id' => 'site',
        'total' => 7, 'done' => 4, 'message' => 'Writing the manifest.', 'created_at' => now()->subMinutes(10),
    ]);
    $waiting = JobsFixtures::job(['state' => 'waiting', 'starter' => 'person', 'started_by' => $person->id, 'created_at' => now()->subHour()]);
    $running = JobsFixtures::job(['created_at' => now()->subHour()]);
    $finished = JobsFixtures::job(['state' => 'finished', 'done' => 400, 'message' => 'Done.', 'ended_at' => now()->subMinutes(30)]);

    ($this->settle)();
    $jobs = TrackedJob::query()->get()->keyBy('id');

    expect($jobs[$backup]->state)->toBe(JobState::Finished)
        ->and($jobs[$backup]->message)->toBe(SettleRestoredSite::RESTORED_FROM)
        ->and([$jobs[$backup]->done, $jobs[$backup]->total])->toBe([7, 7])
        ->and($jobs[$waiting]->state)->toBe(JobState::Failed)
        ->and($jobs[$waiting]->message)->toBe(SettleRestoredSite::NEVER_RAN)
        ->and($jobs[$running]->state)->toBe(JobState::Failed)
        ->and($jobs[$running]->message)->toBe(SettleRestoredSite::UNKNOWN)
        ->and(CarbonImmutable::parse((string) $jobs[$running]->ended_at)->equalTo(now()->subMinutes(5)))->toBeTrue()
        ->and($jobs[$finished]->state)->toBe(JobState::Finished)
        ->and($jobs[$finished]->message)->toBe('Done.')
        ->and(InboxItem::query()->count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\RunnableBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->backups = null;
});

afterEach(function (): void {
    $this->backups?->delete();
});

it('copies the backup to every other place in use, and checks it there', function (): void {
    $this->backups = RunnableBackups::atFolders(3);

    $result = app(BackupSite::class)->run(BackupTrigger::Command);
    $steps = BackupRun::query()->where('parent_id', $result->runId)->get();
    $copies = $steps->where('kind', BackupRunKind::Copy);

    expect($result->allWell())->toBeTrue(implode(' ', $result->problems))
        ->and($this->backups->runsAt(1)[0]->runId)->toBe($result->runId)
        ->and($this->backups->runsAt(1)[0]->complete())->toBeTrue()
        ->and($this->backups->runsAt(2)[0]->complete())->toBeTrue()
        ->and($copies->pluck('destination_id')->all())->toEqualCanonicalizing([$this->backups->places[1]->id, $this->backups->places[2]->id])
        ->and($copies->every(fn (BackupRun $copy): bool => $copy->state === BackupRunState::Finished))->toBeTrue()
        ->and($steps->where('kind', BackupRunKind::Check)->count())->toBe(3);
});

it('catches a place up on everything it lacks', function (): void {
    $this->backups = RunnableBackups::atFolders(2);
    $second = $this->backups->places[1];
    // Away for the first night: not in use.
    BackupDestination::query()->whereKey($second->id)->update(['state' => BackupDestinationState::Removed->value, 'removed_at' => now()]);
    $first = app(BackupSite::class)->run(BackupTrigger::Command);
    BackupDestination::query()->whereKey($second->id)->update(['state' => BackupDestinationState::Ready->value, 'removed_at' => null]);

    $next = app(BackupSite::class)->run(BackupTrigger::Command);

    expect($next->allWell())->toBeTrue(implode(' ', $next->problems))
        ->and(array_map(fn ($run): string => $run->runId, $this->backups->runsAt(1)))->toBe([$next->runId, $first->runId]);
});

it('fails one place’s copy alone, in words, and copies to the others', function (): void {
    $this->backups = RunnableBackups::atFolders(3);
    $this->backups->repositories[1]->delete();

    $result = app(BackupSite::class)->run(BackupTrigger::Command);
    $copy = BackupRun::query()->where('parent_id', $result->runId)->where('destination_id', $this->backups->places[1]->id)->sole();

    expect($result->succeeded)->toBeTrue()
        ->and($result->problems)->toBe(['Teme found no backups at Place 2. Check its address, and that the storage is still there.'])
        ->and($copy->kind)->toBe(BackupRunKind::Copy)
        ->and($copy->state)->toBe(BackupRunState::Failed)
        ->and($this->backups->places[1]->fresh()->state)->toBe(BackupDestinationState::Failing)
        ->and($this->backups->runsAt(2)[0]->runId)->toBe($result->runId)
        ->and(BackupRun::query()->findOrFail($result->runId)->state)->toBe(BackupRunState::Finished);
});

it('copies from a place restic can open beside the target, a folder first, and from none when it can’t', function (): void {
    $folder = BackupRecords::destination();
    $s3 = BackupRecords::destination(['kind' => BackupDestinationKind::S3]);
    $keys = fn (string $id): ResticRepository => new ResticRepository('s3:https://s3.example.com/b', 'key', ['AWS_ACCESS_KEY_ID' => $id, 'AWS_SECRET_ACCESS_KEY' => 'secret']);
    $holders = [
        ['place' => $s3, 'repository' => $keys('one')],
        ['place' => $folder, 'repository' => new ResticRepository('/var/backups/teme', 'key')],
    ];

    expect(BackupSite::copySource($holders, $keys('one'))['place']->id)->toBe($folder->id)
        ->and(BackupSite::copySource($holders, $keys('two'))['place']->id)->toBe($folder->id)
        ->and(BackupSite::copySource([$holders[0]], $keys('one'))['place']->id)->toBe($s3->id)
        ->and(BackupSite::copySource([$holders[0]], $keys('two')))->toBeNull();
});

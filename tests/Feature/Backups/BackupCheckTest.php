<?php

declare(strict_types=1);

use App\Domain\Backups\BackupCheck;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Manifest;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScriptedRestic;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->backups = RunnableBackups::atFolders(1);
    $this->result = app(BackupSite::class)->run(BackupTrigger::Command);
    $this->run = $this->backups->runsAt(0)[0];
    $this->repository = $this->backups->repositories[0]->repository;
    $this->manifest = Manifest::fromJson(json_encode(
        app(Restic::class)->run($this->repository, ['dump', $this->run->parts[BackupSnapshots::MANIFEST], BackupSnapshots::MANIFEST_FILE])->json(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));
    $this->check = fn (?Manifest $manifest = null): ?string => app(BackupCheck::class)->at(
        $this->result->runId, BackupTrigger::Command, null, $this->backups->places[0]->fresh(), $this->repository, $manifest ?? $this->manifest,
    );
});

afterEach(function (): void {
    $this->backups->delete();
});

it('checks every backup where it landed: all four parts, the manifest read back, and restic’s check, as a run under it', function (): void {
    $check = BackupRun::query()->where('parent_id', $this->result->runId)->sole();

    expect($this->result->allWell())->toBeTrue(implode(' ', $this->result->problems))
        ->and($check->kind)->toBe(BackupRunKind::Check)
        ->and($check->state)->toBe(BackupRunState::Finished)
        ->and($check->started_by)->toBe(BackupTrigger::Command)
        ->and($check->destination_id)->toBe($this->backups->places[0]->id)
        ->and(array_keys($check->figures))->toBe(['seconds'])
        ->and(($this->check)())->toBeNull();
});

it('fails the check, in words, when a part of the run is missing at the place', function (): void {
    app(Restic::class)->run($this->repository, ['forget', $this->run->parts[BackupSnapshots::MEDIA]]);

    $problem = ($this->check)();
    $check = BackupRun::query()->where('parent_id', $this->result->runId)->where('kind', BackupRunKind::Check->value)->where('state', BackupRunState::Failed->value)->sole();

    expect($problem)->toBe('The backup at Place 1 isn’t all there: some of its parts are missing.')
        ->and($check?->state)->toBe(BackupRunState::Failed)
        ->and($check?->message)->toBe($problem)
        ->and($this->backups->places[0]->fresh()->state)->toBe(BackupDestinationState::Failing)
        ->and($this->backups->places[0]->fresh()->last_error)->toBe($problem);
});

it('fails the check when the manifest read back isn’t the one written', function (): void {
    $other = Manifest::fromJson(json_encode([...$this->manifest->toArray(), 'teme' => '9.9.9'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    expect(($this->check)($other))->toBe('The manifest read back from Place 1 isn’t the one Teme wrote.');
});

it('fails the check when restic finds damage', function (): void {
    // Only restic's check answers as damaged; every other call runs for real (I-2400).
    Process::fake(['*check*' => Process::result('', ScriptedRestic::exitError(1, 'Fatal: repository contains errors'), 1)]);

    expect(($this->check)())->toBe('restic found damage in the backups at Place 1. Run restic check there to see it.');
});

<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\Queries\BackupPlaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->backups = null;
});

afterEach(function (): void {
    $this->backups?->delete();
});

it('lists the places in use, oldest first, never a removed one', function (): void {
    $newer = BackupRecords::destination(['created_at' => now()->subMinute()]);
    $failing = BackupRecords::destination(['state' => BackupDestinationState::Failing, 'last_error' => 'Gone.', 'created_at' => now()->subHour()]);
    BackupRecords::destination(['state' => BackupDestinationState::Removed, 'removed_at' => now(), 'created_at' => now()->subDay()]);

    expect(app(BackupPlaces::class)->inUse()->pluck('id')->all())->toBe([$failing->id, $newer->id]);
});

it('reads the machine key the runs open places with, or says there is none', function (): void {
    expect(app(BackupPlaces::class)->machineKey())->toBeNull();

    $this->backups = RunnableBackups::atFolders(1);

    expect(app(BackupPlaces::class)->machineKey())->toBe(TemporaryRepository::KEY);
});

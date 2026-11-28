<?php

declare(strict_types=1);

use App\Domain\Backups\Verify\TemporaryDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\OtherSession;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['database.connections.'.TemporaryDatabase::CONNECTION => [...DB::connection()->getConfig(), 'database' => 'postgres']]);
    DB::purge(TemporaryDatabase::CONNECTION);
});

afterEach(function (): void {
    OtherSession::close();
    DB::purge(TemporaryDatabase::CONNECTION);
});

function temporaryExists(string $name): bool
{
    return DB::connection(TemporaryDatabase::CONNECTION)->selectOne('SELECT 1 AS present FROM pg_database WHERE datname = ?', [$name]) !== null;
}

it('drops a leftover and says how many, under the backup lock', function (): void {
    $leftover = TemporaryDatabase::create();

    $this->artisan('teme:backup:sweep')->expectsOutput('Dropped 1 temporary restore database(s).')->assertSuccessful();

    expect(temporaryExists($leftover))->toBeFalse();
});

it('says there is nothing to sweep when none was left behind', function (): void {
    $this->artisan('teme:backup:sweep')->expectsOutput('No temporary restore databases left behind.')->assertSuccessful();
});

it('says nothing to sweep when the restore account is not named', function (): void {
    config(['database.connections.'.TemporaryDatabase::CONNECTION.'.username' => null]);

    $this->artisan('teme:backup:sweep')->expectsOutput('No restore account is named, so there is nothing to sweep.')->assertSuccessful();
});

it('waits for the next day while a backup holds the lock, and leaves the leftover alone', function (): void {
    $leftover = TemporaryDatabase::create();
    OtherSession::holdBackupLock();

    $this->artisan('teme:backup:sweep')->expectsOutput('A backup is running, so the sweep waits for tomorrow.')->assertSuccessful();

    expect(temporaryExists($leftover))->toBeTrue();

    OtherSession::close();
    TemporaryDatabase::drop($leftover);
});

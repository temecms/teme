<?php

declare(strict_types=1);

use App\Domain\Backups\BackupRefused;
use App\Domain\Backups\Verify\SpareDatabase;
use App\Domain\Backups\Verify\TemporaryDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The test's own superuser stands in for the restore account, which holds CREATEDB in production.
    config(['database.connections.'.TemporaryDatabase::CONNECTION => [...DB::connection()->getConfig(), 'database' => 'postgres']]);
    DB::purge(TemporaryDatabase::CONNECTION);
});

afterEach(function (): void {
    DB::purge(TemporaryDatabase::CONNECTION);
});

function databaseExists(string $name): bool
{
    return DB::connection(TemporaryDatabase::CONNECTION)->selectOne('SELECT 1 AS present FROM pg_database WHERE datname = ?', [$name]) !== null;
}

it('is configured only when the restore account is named', function (): void {
    expect(TemporaryDatabase::configured())->toBeTrue();

    config(['database.connections.'.TemporaryDatabase::CONNECTION.'.username' => null]);

    expect(TemporaryDatabase::configured())->toBeFalse();
});

it('refuses to open a temporary restore database when the restore account is not named', function (): void {
    config(['database.connections.'.TemporaryDatabase::CONNECTION.'.username' => null]);

    expect(fn () => SpareDatabase::openTemporary())->toThrow(BackupRefused::class, TemporaryDatabase::NOT_CONFIGURED);
});

it('creates a database whose name carries the prefix and a random suffix, and drops it', function (): void {
    $name = TemporaryDatabase::create();

    expect($name)->toMatch('/^teme_restore_[0-9a-f]{16}$/')
        ->and(databaseExists($name))->toBeTrue();

    TemporaryDatabase::drop($name);

    expect(databaseExists($name))->toBeFalse();
});

it('refuses to drop a database whose name is not one it made', function (): void {
    expect(fn () => TemporaryDatabase::drop('teme_restore_abc'))->toThrow(InvalidArgumentException::class);
    expect(fn () => TemporaryDatabase::drop('postgres'))->toThrow(InvalidArgumentException::class);
});

it('opens a temporary database, and closing it drops it', function (): void {
    $spare = SpareDatabase::openTemporary();
    $name = $spare->name;

    expect($name)->toStartWith('teme_restore_')
        ->and(databaseExists($name))->toBeTrue();

    $spare->close();

    expect(databaseExists($name))->toBeFalse();
});

it('sweeps leftover temporary databases by their prefix, and leaves every other database alone', function (): void {
    $leftover = TemporaryDatabase::create();
    $other = 'teme_restore_notmine';
    DB::connection(TemporaryDatabase::CONNECTION)->statement("CREATE DATABASE {$other} TEMPLATE template0");

    try {
        expect(TemporaryDatabase::sweep())->toBe(1)
            ->and(databaseExists($leftover))->toBeFalse()
            ->and(databaseExists($other))->toBeTrue();
    } finally {
        DB::connection(TemporaryDatabase::CONNECTION)->statement("DROP DATABASE IF EXISTS {$other}");
    }
});

it('sweeps nothing when the restore account is not named', function (): void {
    config(['database.connections.'.TemporaryDatabase::CONNECTION.'.username' => null]);

    expect(TemporaryDatabase::sweep())->toBe(0);
});

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

/** Another site's prefix on this server: a tag it never shares with this one. */
function otherSiteName(): string
{
    return 'teme_restore_'.substr(hash('sha256', 'another site'), 0, 8).'_'.bin2hex(random_bytes(8));
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

it('creates a database named with this site’s tag, a random suffix and the prefix, and drops it', function (): void {
    $name = TemporaryDatabase::create();

    expect($name)->toStartWith(TemporaryDatabase::prefix())
        ->and($name)->toMatch('/^teme_restore_[0-9a-f]{8}_[0-9a-f]{16}\z/')
        ->and(databaseExists($name))->toBeTrue();

    TemporaryDatabase::drop($name);

    expect(databaseExists($name))->toBeFalse();
});

it('refuses to drop a name it did not make, however it is dressed up', function (): void {
    $prefix = TemporaryDatabase::prefix();
    $names = [
        'teme_restore_abc',
        'postgres',
        'x'.$prefix.'0123456789abcdef',
        'teme-restore-'.substr($prefix, 14, 8).'-0123456789abcdef',
        $prefix.'0123456789ABCDEF',
        $prefix.'0123456789abcdef'."\n",
        otherSiteName(),
    ];

    foreach ($names as $name) {
        expect(fn () => TemporaryDatabase::drop($name))->toThrow(InvalidArgumentException::class);
    }
});

it('opens a temporary database, and closing it drops it', function (): void {
    $spare = SpareDatabase::openTemporary();
    $name = $spare->name;

    expect($name)->toStartWith(TemporaryDatabase::prefix())
        ->and(databaseExists($name))->toBeTrue();

    $spare->close();

    expect(databaseExists($name))->toBeFalse();
});

it('leaves no database of its own behind when the restore account cannot make one', function (): void {
    config(['database.connections.'.TemporaryDatabase::CONNECTION.'.username' => 'teme_no_such_role_'.bin2hex(random_bytes(4))]);
    DB::purge(TemporaryDatabase::CONNECTION);

    expect(fn () => SpareDatabase::openTemporary())->toThrow(BackupRefused::class);

    config(['database.connections.'.TemporaryDatabase::CONNECTION => [...DB::connection()->getConfig(), 'database' => 'postgres']]);
    DB::purge(TemporaryDatabase::CONNECTION);

    $left = DB::connection(TemporaryDatabase::CONNECTION)->select('SELECT datname AS name FROM pg_catalog.pg_database WHERE datname LIKE ?', [str_replace('_', '\\_', TemporaryDatabase::prefix()).'%']);
    expect($left)->toBe([]);
});

it('sweeps only this site’s leftovers that its own role owns and nothing is using', function (): void {
    $leftover = TemporaryDatabase::create();
    $decoy = otherSiteName();
    DB::connection(TemporaryDatabase::CONNECTION)->statement('CREATE DATABASE '.$decoy.' TEMPLATE template0');

    try {
        expect(TemporaryDatabase::sweep())->toBe(1)
            ->and(databaseExists($leftover))->toBeFalse()
            ->and(databaseExists($decoy))->toBeTrue();
    } finally {
        DB::connection(TemporaryDatabase::CONNECTION)->statement("DROP DATABASE IF EXISTS {$decoy}");
    }
});

it('leaves a temporary database that another role owns alone', function (): void {
    $role = 'teme_other_'.bin2hex(random_bytes(4));
    $name = TemporaryDatabase::prefix().bin2hex(random_bytes(8));
    DB::connection(TemporaryDatabase::CONNECTION)->statement("CREATE ROLE {$role} NOLOGIN");
    DB::connection(TemporaryDatabase::CONNECTION)->statement("CREATE DATABASE {$name} OWNER {$role} TEMPLATE template0");

    try {
        expect(TemporaryDatabase::sweep())->toBe(0)
            ->and(databaseExists($name))->toBeTrue();
    } finally {
        DB::connection(TemporaryDatabase::CONNECTION)->statement("DROP DATABASE IF EXISTS {$name}");
        DB::connection(TemporaryDatabase::CONNECTION)->statement("DROP ROLE IF EXISTS {$role}");
    }
});

it('leaves a temporary database alone while a restore is connected to it', function (): void {
    $name = TemporaryDatabase::create();
    $session = DB::connectUsing('restore_probe', [...config('database.connections.'.TemporaryDatabase::CONNECTION), 'database' => $name, 'name' => 'restore_probe'], force: true);

    try {
        $session->select('SELECT 1');

        expect(TemporaryDatabase::sweep())->toBe(0)
            ->and(databaseExists($name))->toBeTrue();
    } finally {
        DB::purge('restore_probe');
        TemporaryDatabase::drop($name);
    }
});

it('sweeps nothing when the restore account is not named', function (): void {
    config(['database.connections.'.TemporaryDatabase::CONNECTION.'.username' => null]);

    expect(TemporaryDatabase::sweep())->toBe(0);
});

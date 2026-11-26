<?php

declare(strict_types=1);

use App\Domain\Deployment\DestructiveCommands;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\DB;

uses(WithConsoleEvents::class);

afterEach(function (): void {
    // The prohibition is static: leave the next test's migrate:fresh free.
    DB::prohibitDestructiveCommands(false);
});

it('lets the commands empty only a test or benchmark database', function (): void {
    expect(DestructiveCommands::disposable('teme_test'))->toBeTrue()
        ->and(DestructiveCommands::disposable('teme_test_3'))->toBeTrue()
        ->and(DestructiveCommands::disposable('teme_bench'))->toBeTrue()
        ->and(DestructiveCommands::disposable('balafama'))->toBeFalse()
        ->and(DestructiveCommands::disposable('teme'))->toBeFalse()
        ->and(DestructiveCommands::disposable('teme_testing'))->toBeFalse()
        ->and(DestructiveCommands::disposable('my_test_site'))->toBeFalse();
});

it('refuses a site database whatever the environment, and production always, unless allowed', function (): void {
    expect(DestructiveCommands::refused('balafama', false, false))->toBeTrue()
        ->and(DestructiveCommands::refused('balafama', true, false))->toBeTrue()
        ->and(DestructiveCommands::refused('teme_test', false, false))->toBeFalse()
        ->and(DestructiveCommands::refused('teme_test', true, false))->toBeTrue()
        ->and(DestructiveCommands::refused('balafama', false, true))->toBeFalse()
        ->and(DestructiveCommands::refused('balafama', true, true))->toBeFalse();
});

it('reads the database a DB_URL names over the database key', function (): void {
    expect(DestructiveCommands::database(['driver' => 'pgsql', 'url' => 'pgsql://u:p@db:5432/balafama', 'database' => 'teme_test']))->toBe('balafama')
        ->and(DestructiveCommands::database(['driver' => 'pgsql', 'url' => null, 'database' => 'teme_test']))->toBe('teme_test');
});

it('refuses db:wipe on a site database named with --database, and says why', function (): void {
    config(['database.connections.site' => array_merge(config('database.connections.pgsql'), ['url' => null, 'database' => 'balafama_site'])]);

    $this->artisan('db:wipe', ['--database' => 'site', '--force' => true])
        ->expectsOutputToContain('Teme refused to empty the database "balafama_site"')
        ->assertFailed();

    expect(DestructiveCommands::refusal('balafama_site'))->toContain(DestructiveCommands::ALLOW.'=1');
});

it('refuses migrate:fresh on a site database even with --force', function (): void {
    config(['database.connections.site' => array_merge(config('database.connections.pgsql'), ['url' => null, 'database' => 'balafama_site'])]);

    $this->artisan('migrate:fresh', ['--database' => 'site', '--force' => true])
        ->expectsOutputToContain('Teme refused to empty the database "balafama_site"')
        ->assertFailed();
});

it('lets the operator through when TEME_ALLOW_DATABASE_WIPE is set', function (): void {
    config(['teme.allow_database_wipe' => true]);

    DestructiveCommands::guardDefault(true);

    expect(DestructiveCommands::refused('balafama', true, true))->toBeFalse();
});

it('leaves the test database free, so the suite can refresh it', function (): void {
    DestructiveCommands::guardDefault(false);

    expect(DestructiveCommands::databaseOf())->toEndWith('_test')
        ->and(DestructiveCommands::refused(DestructiveCommands::databaseOf(), false, false))->toBeFalse();
});

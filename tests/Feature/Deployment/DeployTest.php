<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Doctor;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Deployment\Jobs\RunReleaseRebuild;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Backups\ScratchSite;
use Tests\Fixtures\Deployment\DeployLockSession;
use Tests\Fixtures\Deployment\DeployRun;
use Tests\Fixtures\Deployment\StubCheck;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DeployRun::isolatePageCache();
    // A database of the test's own, which the deploy migrates outside the test's transaction.
    $this->site = ScratchSite::open();
    // An account exists, so a deploy prints no first-run lines (issue 246).
    User::factory()->create();
    // Each test chooses the doctor's findings; the checks have tests of their own.
    app()->instance(Doctor::class, new Doctor([]));
});

afterEach(function (): void {
    DeployLockSession::close();
    $this->site->close();
});

it('brings a database with a waiting migration to this release, saying each step (I-377)', function (): void {
    app('migrator')->path(DeployRun::migrations('probe'));

    $run = DeployRun::here();
    $lines = [
        'Checking this server with the doctor.',
        '1 migration is waiting:',
        '  '.DeployRun::PROBE,
        "Backups aren't set up on this site, so nothing was backed up before migrating. To set them up: php artisan teme:backup:setup.",
        'Migrating.',
        'Telling the queue workers to restart with this release.',
        'Clearing the page cache.',
        "The page cache is off, so it isn't warmed.",
        'Deployed: 1 migration ran.',
    ];

    expect($run->status)->toBe(0)
        ->and(Schema::hasTable('deploy_probe'))->toBeTrue()
        ->and($run->inOrder($lines))->toBe($lines);
});

it('answers with --json in one line of JSON, and nothing else, with its exit code (I-3189)', function (): void {
    app('migrator')->path(DeployRun::migrations('probe'));

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(0)
        ->and(substr_count(trim($run->output), PHP_EOL))->toBe(0)
        ->and($run->json)->toBe([
            'command' => 'teme:deploy',
            'format' => 1,
            'check' => false,
            'outcome' => 'deployed',
            'exit' => 0,
            'sentence' => 'Deployed: 1 migration ran.',
            'findings' => [],
            'lock' => ['waited' => false, 'seconds' => 0.0],
            'migrations' => ['new_database' => false, 'pending' => [DeployRun::PROBE], 'ran' => [DeployRun::PROBE], 'needs_maintenance' => [], 'error' => null],
            'backup' => ['state' => 'not-set-up', 'run' => null, 'place' => null, 'sentence' => null, 'problems' => []],
            'rebuilds' => [],
            'steps' => ['preflight' => 'done', 'lock' => 'done', 'backup' => 'skipped', 'migrate' => 'done', 'recheck' => 'skipped', 'rebuilds' => 'skipped', 'queue_restart' => 'done', 'cache_clear' => 'done', 'cache_warm' => 'skipped'],
        ]);
});

it('finds nothing to migrate on a database at this release, and still restarts the workers and clears the page cache', function (): void {
    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(0)
        ->and($run->json['sentence'])->toBe('Deployed: no migration was waiting.')
        ->and($run->json['migrations']['pending'])->toBe([])
        ->and($run->json['backup']['state'])->toBe('not-needed')
        ->and($run->json['steps'])->toMatchArray(['backup' => 'skipped', 'migrate' => 'skipped', 'queue_restart' => 'done', 'cache_clear' => 'done'])
        ->and(Cache::get('illuminate:queue:restart'))->not->toBeNull();
});

it('refuses on any of the doctor\'s errors, changing nothing, and shows its warnings too', function (): void {
    app()->instance(StubCheck::class, new StubCheck('Stub', [
        new Finding(Severity::Error, 'Something is broken.', 'Mend it.'),
        new Finding(Severity::Warning, 'Something is odd.', 'Look at it.'),
        new Finding(Severity::Ok, 'All is well here.'),
    ]));
    app(Doctor::class)->register('stub', StubCheck::class);
    app('migrator')->path(DeployRun::migrations('probe'));

    $run = DeployRun::here();
    $json = DeployRun::here(['--json' => true])->json;

    expect($run->status)->toBe(2)
        ->and(Schema::hasTable('deploy_probe'))->toBeFalse()
        ->and($run->output)->toBe(implode(PHP_EOL, [
            'Checking this server with the doctor.',
            '  error    Stub: Something is broken.',
            '           Fix: Mend it.',
            '  warning  Stub: Something is odd.',
            '           Fix: Look at it.',
            'Nothing was changed: the doctor found 1 error. Fix it, then run php artisan teme:deploy again.',
            '',
        ]))
        ->and($json['outcome'])->toBe('refused')
        ->and($json['exit'])->toBe(2)
        ->and($json['findings'])->toBe([
            ['check' => 'stub', 'title' => 'Stub', 'severity' => 'error', 'message' => 'Something is broken.', 'fix' => 'Mend it.'],
            ['check' => 'stub', 'title' => 'Stub', 'severity' => 'warning', 'message' => 'Something is odd.', 'fix' => 'Look at it.'],
        ])
        ->and($json['lock'])->toBeNull()
        ->and($json['steps'])->toMatchArray(['preflight' => 'failed', 'lock' => 'not-run', 'migrate' => 'not-run']);
});

it('goes on past the doctor\'s warnings', function (): void {
    app()->instance(StubCheck::class, new StubCheck('Stub', [new Finding(Severity::Warning, 'Something is odd.', 'Look at it.')]));
    app(Doctor::class)->register('stub', StubCheck::class);
    app('migrator')->path(DeployRun::migrations('probe'));

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(0)
        ->and($run->json['findings'])->toHaveCount(1)
        ->and(Schema::hasTable('deploy_probe'))->toBeTrue();
});

it('waits for another deploy, and gives up after --wait seconds, changing nothing (I-377)', function (): void {
    app('migrator')->path(DeployRun::migrations('probe'));
    DeployLockSession::hold();

    $run = DeployRun::here(['--wait' => 1]);
    $lines = [
        'Another deploy is running on this database. Waiting for it to finish.',
        'Another deploy still held the lock after 1 second, so nothing was changed. Run php artisan teme:deploy again once it has finished.',
    ];

    expect($run->status)->toBe(7)
        ->and(Schema::hasTable('deploy_probe'))->toBeFalse()
        ->and($run->inOrder($lines))->toBe($lines);
});

it('refuses a migration that breaks the release before unless the site is in maintenance mode (I-383)', function (): void {
    app('migrator')->path(DeployRun::migrations('flagged'));
    // Maintenance in this process only: the file driver would close every parallel worker's site.
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->forgetInstance(MaintenanceModeManager::class);

    $refused = DeployRun::here(['--json' => true]);

    expect($refused->status)->toBe(2)
        ->and($refused->json['sentence'])->toBe('Nothing was changed. Run php artisan down, then php artisan teme:deploy again, and php artisan up once the new release is serving.')
        ->and($refused->json['migrations']['needs_maintenance'])->toBe([DeployRun::FLAGGED])
        ->and($refused->json['steps'])->toMatchArray(['lock' => 'done', 'backup' => 'not-run', 'migrate' => 'not-run'])
        ->and(Schema::hasTable('deploy_flagged'))->toBeFalse();

    app()->maintenanceMode()->activate([]);
    $deployed = DeployRun::here(['--json' => true]);

    expect($deployed->status)->toBe(0)
        ->and($deployed->json['migrations']['ran'])->toBe([DeployRun::FLAGGED])
        ->and($deployed->json['steps']['cache_warm'])->toBe('skipped')
        ->and(Schema::hasTable('deploy_flagged'))->toBeTrue();
});

it('queues the rebuilds the release\'s migrations ask for, as the operator (I-3113)', function (): void {
    Queue::fake();
    app('migrator')->path(DeployRun::migrations('asks'));

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(0)
        ->and($run->json['rebuilds'])->toBe(['search'])
        ->and($run->json['steps']['rebuilds'])->toBe('done');
    Queue::assertPushed(RunReleaseRebuild::class, fn (RunReleaseRebuild $job): bool => $job->rebuild === 'search' && $job->actor->isOperator());
});

it('stops with the database already migrated when a later step fails, so running it again finishes', function (): void {
    app('migrator')->path(DeployRun::migrations('drops'));

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(5)
        ->and($run->json['outcome'])->toBe('unfinished')
        ->and($run->json['sentence'])->toBe("Queueing this release's rebuilds failed, so the deploy stopped there. The database already matches this release: run php artisan teme:deploy again to finish. The log has the details.")
        ->and($run->json['steps'])->toMatchArray(['migrate' => 'done', 'rebuilds' => 'failed', 'queue_restart' => 'not-run', 'cache_clear' => 'not-run', 'cache_warm' => 'not-run']);
});

it('stops when a migration fails, saying the ones before it have run', function (): void {
    app('migrator')->path(DeployRun::migrations('probe'));
    app('migrator')->path(DeployRun::migrations('failing'));

    $run = DeployRun::here();
    $lines = [
        'Migrating.',
        'A migration failed: ',
        "The migrations before it have run and the rest haven't. Fix what stopped it, then run php artisan teme:deploy again.",
    ];

    expect($run->status)->toBe(4)
        ->and($run->inOrder($lines))->toBe($lines)
        ->and($run->output)->toContain('This migration fails on purpose.')
        ->and(Schema::hasTable('deploy_probe'))->toBeTrue()
        ->and(Schema::hasTable('deploy_failed'))->toBeFalse();
});

it('examines again after migrating the checks that couldn\'t run before, and stops unfinished when one still can\'t (I-377)', function (): void {
    $pdo = new PDOException('SQLSTATE[42P01]: a table that never comes');
    (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '42P01');
    app()->instance(StubCheck::class, new StubCheck('Stub', [], new QueryException('pgsql', 'select 1', [], $pdo)));
    app(Doctor::class)->register('stub', StubCheck::class);
    app('migrator')->path(DeployRun::migrations('probe'));

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(5)
        ->and($run->json['findings'][0])->toMatchArray(['check' => 'stub', 'severity' => 'warning', 'message' => Doctor::NOT_YET])
        ->and($run->json['sentence'])->toBe("Stub still couldn't run after migrating, so the deploy stopped there. The database already matches this release: fix what php artisan teme:doctor says, then run php artisan teme:deploy again.")
        ->and($run->json['steps'])->toMatchArray(['migrate' => 'done', 'recheck' => 'failed', 'rebuilds' => 'not-run'])
        ->and(Schema::hasTable('deploy_probe'))->toBeTrue();
});

it('carries on without a backup on a site from before backups, whose backup tables a waiting migration makes', function (): void {
    $backupMigrations = [
        '2027_01_05_100000_create_backup_destinations_table',
        '2027_01_05_100100_create_backup_runs_table',
        '2027_01_05_100200_create_backup_keys_table',
        '2027_01_10_100000_add_paused_at_to_backup_destinations',
        '2027_01_11_100000_create_backup_alerts_table',
    ];
    DB::statement('DROP TABLE backup_alerts, backup_runs, backup_keys, backup_destinations CASCADE');
    DB::table('migrations')->whereIn('migration', $backupMigrations)->delete();

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(0)
        ->and($run->json['migrations']['new_database'])->toBeFalse()
        ->and($run->json['migrations']['ran'])->toBe($backupMigrations)
        ->and($run->json['backup']['state'])->toBe('not-set-up')
        ->and(Schema::hasTable('backup_destinations'))->toBeTrue();
});

it('stops on the unexpected with exit 1, and still answers in JSON', function (): void {
    app('migrator')->path(DeployRun::migrations('broken'));

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(1)
        ->and($run->json)->toMatchArray(['outcome' => 'failed', 'exit' => 1, 'sentence' => "The deploy stopped on an error Teme didn't expect. The log has its details."])
        ->and($run->json['steps'])->toMatchArray(['lock' => 'done', 'backup' => 'not-run', 'migrate' => 'not-run']);
});

it('says with --check that nothing waits, exiting 0', function (): void {
    $run = DeployRun::here(['--check' => true]);

    expect($run->status)->toBe(0)
        ->and($run->output)->toBe('No migrations are waiting: the database already matches this release.'.PHP_EOL);
});

it('says with --check which migrations wait and which need maintenance mode, exiting 6 and running none', function (): void {
    app('migrator')->path(DeployRun::migrations('probe'));
    app('migrator')->path(DeployRun::migrations('flagged'));

    $run = DeployRun::here(['--check' => true]);
    $json = DeployRun::here(['--check' => true, '--json' => true])->json;

    expect($run->status)->toBe(6)
        ->and($run->output)->toBe(implode(PHP_EOL, [
            '2 migrations are waiting:',
            '  '.DeployRun::PROBE,
            '  '.DeployRun::FLAGGED,
            DeployRun::FLAGGED." changes the database in a way the release running now can't work with, so it runs only while the site is in maintenance mode.",
            'Run php artisan teme:deploy to run them.',
            '',
        ]))
        ->and(Schema::hasTable('deploy_probe'))->toBeFalse()
        ->and($json)->toBe([
            'command' => 'teme:deploy',
            'format' => 1,
            'check' => true,
            'outcome' => 'pending',
            'exit' => 6,
            'sentence' => 'Run php artisan teme:deploy to run them.',
            'findings' => [],
            'lock' => null,
            'migrations' => ['new_database' => false, 'pending' => [DeployRun::PROBE, DeployRun::FLAGGED], 'ran' => [], 'needs_maintenance' => [DeployRun::FLAGGED], 'error' => null],
            'backup' => null,
            'rebuilds' => [],
            'steps' => [],
        ]);
});

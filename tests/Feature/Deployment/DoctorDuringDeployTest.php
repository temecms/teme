<?php

declare(strict_types=1);

use App\Domain\Backups\Tools\BackupTools;
use App\Domain\Backups\Verify\SpareDatabase;
use App\Domain\Deployment\BackupsSetUp;
use App\Domain\Deployment\Health\Checks\BackupPrograms;
use App\Domain\Deployment\Health\Checks\TestRestoreDatabase;
use App\Domain\Deployment\Health\Doctor;
use App\Domain\Deployment\Health\DoctorResult;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\PendingMigrations;
use App\Domain\Deployment\Health\Severity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\VerifyDatabase;
use Tests\Fixtures\Deployment\DeployRun;
use Tests\Fixtures\Deployment\OtherRole;
use Tests\Fixtures\Deployment\RestrictedRole;
use Tests\Fixtures\Deployment\SecondStubCheck;
use Tests\Fixtures\Deployment\StubCheck;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->spare = null;
    $this->other = null;
    $this->role = null;
    $this->backups = null;
    // A QueryException carrying PostgreSQL's SQLSTATE, as a check's query throws it.
    $this->failure = function (string $state): QueryException {
        $pdo = new PDOException("SQLSTATE[{$state}]: a check's query failed");
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $state);

        return new QueryException('pgsql', 'select 1', [], $pdo);
    };
});

afterEach(function (): void {
    $this->backups?->delete();
    $this->spare?->drop();
    $this->other?->drop();
    $this->role?->close();
});

it('registers the backup programs and the test restore database beside core\'s checks (I-377)', function (): void {
    expect(Doctor::NOT_YET)->toBe("The check couldn't run yet: it reads a table or column that a migration which hasn't run makes.")
        ->and(app(Doctor::class)->keys())->toContain('backup-tools', 'verify-database')
        ->and(array_keys(Doctor::CORE))->not->toContain('backup-tools')
        ->and(array_keys(Doctor::CORE))->not->toContain('verify-database');
});

it('counts a check that reads what a waiting migration makes as a warning, and everything else as an error', function (string $state, bool $waiting, Severity $severity): void {
    if ($waiting) {
        app('migrator')->path(DeployRun::migrations('probe'));
    }

    $failure = ($this->failure)($state);
    $doctor = new Doctor([]);
    app()->instance(StubCheck::class, new StubCheck('Stub', [], $failure));
    $doctor->register('stub', StubCheck::class);

    expect($doctor->examine()[0]->finding)->toEqual($severity === Severity::Warning
        ? new Finding(Severity::Warning, Doctor::NOT_YET, 'Run php artisan teme:deploy, which runs the migrations.')
        : new Finding(Severity::Error, "The check couldn't run: {$failure->getMessage()}"));
})->with([
    'a missing table, while a migration waits' => ['42P01', true, Severity::Warning],
    'a missing column, while a migration waits' => ['42703', true, Severity::Warning],
    'a missing table, with nothing waiting' => ['42P01', false, Severity::Error],
    'any other failure, while a migration waits' => ['23505', true, Severity::Error],
]);

it('names the migrations this code ships that haven\'t run, in the order they run', function (): void {
    app('migrator')->path(DeployRun::migrations('flagged'));
    app('migrator')->path(DeployRun::migrations('probe'));
    $migrations = app(PendingMigrations::class);

    expect($migrations->names())->toBe([DeployRun::PROBE, DeployRun::FLAGGED])
        ->and($migrations->count())->toBe(2)
        ->and($migrations->databaseIsNew())->toBeFalse();
});

it('finds the waiting migrations flagged as breaking the release before (I-383)', function (): void {
    app('migrator')->path(DeployRun::migrations('probe'));
    app('migrator')->path(DeployRun::migrations('flagged'));

    expect(app(PendingMigrations::class)->breakingPreviousRelease([DeployRun::PROBE, DeployRun::FLAGGED]))->toBe([DeployRun::FLAGGED])
        ->and(app(PendingMigrations::class)->breakingPreviousRelease([DeployRun::PROBE]))->toBe([]);
});

it('counts every migration as waiting on a database with no migrations table', function (): void {
    $this->role = RestrictedRole::open();
    $migrations = app(PendingMigrations::class);

    expect($migrations->names())->toBe(array_keys(app('migrator')->getMigrationFiles([...app('migrator')->paths(), database_path('migrations')])))
        ->and($migrations->databaseIsNew())->toBeTrue();
});

it('says the backup programs are ready, with each version and the server\'s', function (): void {
    $report = app(BackupTools::class)->check();
    // The image carries them, and so does the builders' sandbox (scripts/cloud-setup.sh).
    expect($report->ready())->toBeTrue();
    [$restic, $dump, $restore] = $report->tools;

    expect(app(BackupPrograms::class)->examine())->toEqual([
        new Finding(Severity::Ok, "restic {$restic->version}, pg_dump {$dump->version} and pg_restore {$restore->version} can back up this site's PostgreSQL {$report->serverVersion}."),
    ]);
});

it('reports each problem with the backup programs, an error only in production once backups are set up', function (): void {
    $restic = config('teme.backups.restic');
    config(['teme.backups.restic' => '/nonexistent/teme/restic']);
    $elsewhere = app(BackupPrograms::class)->examine();
    app()->instance('env', 'production');
    $notSetUp = app(BackupPrograms::class)->examine();
    // Made with the real restic, which the fixture runs itself; then broken again.
    config(['teme.backups.restic' => $restic]);
    $this->backups = RunnableBackups::atFolders(1);
    config(['teme.backups.restic' => '/nonexistent/teme/restic']);
    $setUp = app(BackupPrograms::class)->examine();

    expect($elsewhere[0])->toEqual(new Finding(
        Severity::Warning,
        'There’s no program at /nonexistent/teme/restic, where TEME_RESTIC_BINARY says restic is. Install restic 0.19.1 there, or correct the path.',
        'php artisan teme:backup:tools shows where Teme looked for each program.',
    ))
        ->and($notSetUp[0])->toEqual($elsewhere[0])
        ->and($setUp[0]->severity)->toBe(Severity::Error)
        ->and($setUp[0]->message)->toBe($elsewhere[0]->message);
});

it('says backups aren\'t set up where their tables don\'t exist yet, instead of failing', function (): void {
    $this->role = RestrictedRole::open();

    expect(app(BackupsSetUp::class)->now())->toBeFalse();
});

it('examines again only the checks it is given', function (): void {
    $doctor = new Doctor([]);
    app()->instance(StubCheck::class, new StubCheck('Stub', [new Finding(Severity::Ok, 'Fine.')]));
    app()->instance(SecondStubCheck::class, new SecondStubCheck('Second stub', [], new RuntimeException('Boom.')));
    $doctor->register('stub', StubCheck::class);
    $doctor->register('second-stub', SecondStubCheck::class);

    expect(array_map(fn (DoctorResult $result): string => $result->key, $doctor->examineOnly(['stub'])))->toBe(['stub'])
        ->and($doctor->examineOnly([]))->toBe([]);
});

it('says no spare database is named, and how to make one, as a warning (I-2406)', function (): void {
    VerifyDatabase::unnamed();
    $role = (string) config('database.connections.teme_verify.username');

    expect(app(TestRestoreDatabase::class)->examine())->toEqual([
        new Finding(Severity::Warning, 'No spare database is named, so the weekly test restore is skipped.', "As a superuser: CREATE DATABASE teme_verify OWNER {$role} TEMPLATE template0; then set TEME_VERIFY_DATABASE=teme_verify."),
    ]);
});

it('is satisfied by a spare database its role owns', function (): void {
    $this->spare = VerifyDatabase::make(asRestrictedRole: true);
    $role = (string) config('database.connections.teme_verify.username');

    expect(app(TestRestoreDatabase::class)->examine())->toEqual([
        new Finding(Severity::Ok, "{$this->spare->name} is ready for the weekly test restore, owned by {$role}."),
    ]);
});

it('warns when the spare database belongs to a role other than the one Teme connects as', function (): void {
    $this->spare = VerifyDatabase::make(asRestrictedRole: true);
    $this->other = OtherRole::make();
    config([
        'database.connections.teme_verify.username' => $this->other->name,
        'database.connections.teme_verify.password' => $this->other->password,
    ]);
    $owner = (string) DB::selectOne('SELECT pg_get_userbyid(datdba) AS owner FROM pg_catalog.pg_database WHERE datname = ?', [$this->spare->name])->owner;

    expect(app(TestRestoreDatabase::class)->examine())->toEqual([
        new Finding(Severity::Warning, "{$this->spare->name} belongs to {$owner}, not {$this->other->name}, so the weekly test restore can't empty it.", "As a superuser: ALTER DATABASE {$this->spare->name} OWNER TO {$this->other->name};"),
    ]);
});

it('warns when the spare database can\'t be reached', function (): void {
    /** @var array<string, mixed> $config */
    $config = DB::connection()->getConfig();
    unset($config['name']);
    config(['database.connections.teme_verify' => [...$config, 'database' => 'teme_doctor_missing_verify']]);
    $role = (string) config('database.connections.teme_verify.username');

    expect(app(TestRestoreDatabase::class)->examine())->toEqual([
        new Finding(Severity::Warning, "Teme couldn't reach the spare database teme_doctor_missing_verify, so the weekly test restore will fail.", "If it doesn't exist, as a superuser: CREATE DATABASE teme_doctor_missing_verify OWNER {$role} TEMPLATE template0;"),
    ]);
});

it('warns when the spare database is the site\'s own', function (): void {
    $this->spare = VerifyDatabase::make();
    config(['database.connections.doctor_site' => [...config('database.connections.teme_verify'), 'name' => 'doctor_site']]);
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('doctor_site');

    try {
        $findings = app(TestRestoreDatabase::class)->examine();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('doctor_site');
    }

    expect($findings)->toEqual([new Finding(Severity::Warning, SpareDatabase::LIVE, 'Name a database of its own, such as teme_verify.')]);
});

it('warns when the spare database\'s name doesn\'t end in _verify, and connects to nothing', function (): void {
    config(['database.connections.teme_verify.database' => 'teme_spare']);

    expect(app(TestRestoreDatabase::class)->examine())->toEqual([
        new Finding(Severity::Warning, 'TEME_VERIFY_DATABASE is teme_spare, but Teme test-restores only into a database whose name ends in _verify and holds only letters, digits, dots, hyphens and underscores.', 'Name a database that ends in _verify, such as teme_verify.'),
    ])->and(DB::getConnections())->not->toHaveKey(SpareDatabase::CONNECTION);
});

<?php

declare(strict_types=1);

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupLock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\OtherSession;

uses(RefreshDatabase::class);

afterEach(function (): void {
    OtherSession::close();
});

it('holds the backup lock on a session of its own while the work runs, and lets go after (I-358)', function (): void {
    $lock = app(BackupLock::class);
    $seen = [];

    $answer = $lock->during(function () use ($lock, &$seen): string {
        $seen = ['elsewhere' => OtherSession::backupRunning(), 'here' => $lock->isHeld()];

        return 'done';
    });

    expect($answer)->toBe('done')
        ->and($seen)->toBe(['elsewhere' => true, 'here' => true])
        ->and(OtherSession::backupRunning())->toBeFalse()
        ->and($lock->isHeld())->toBeFalse();
});

it('lets go when the work throws, whatever it left its transactions in', function (): void {
    $lock = app(BackupLock::class);

    expect(fn () => $lock->during(fn () => throw new RuntimeException('The work failed.')))->toThrow(RuntimeException::class, 'The work failed.')
        ->and(OtherSession::backupRunning())->toBeFalse();

    // The lock's own session, left inside a broken transaction.
    expect(fn () => $lock->during(function (): void {
        $session = DB::connection(BackupLock::CONNECTION);
        $session->beginTransaction();
        $session->statement('SELECT 1 / 0');
    }))->toThrow(QueryException::class)
        ->and(OtherSession::backupRunning())->toBeFalse();

    // The work's own connection broken, as a failed dump inside an Operation
    // would leave it: this test's transaction can run nothing after this.
    expect(fn () => $lock->during(fn () => DB::statement('SELECT 1 / 0')))->toThrow(QueryException::class)
        ->and(OtherSession::backupRunning())->toBeFalse();
});

it('lets go by asking, not by hanging up, when the work leaves the lock’s session in a broken transaction (#223)', function (): void {
    $unlocked = false;
    DB::listen(function (QueryExecuted $query) use (&$unlocked): void {
        $unlocked = $unlocked || ($query->connectionName === BackupLock::CONNECTION && $query->sql === 'SELECT pg_advisory_unlock_all()');
    });

    expect(fn () => app(BackupLock::class)->during(function (): void {
        $session = DB::connection(BackupLock::CONNECTION);
        $session->beginTransaction();
        $session->statement('SELECT 1 / 0');
    }))->toThrow(QueryException::class)
        ->and($unlocked)->toBeTrue()
        ->and(OtherSession::backupRunning())->toBeFalse();
});

it('refuses a second backup while another session holds the lock, running none of its work', function (): void {
    OtherSession::holdBackupLock();
    $ran = false;

    expect(fn () => app(BackupLock::class)->during(function () use (&$ran): void {
        $ran = true;
    }))->toThrow(BackupAlreadyRunning::class, 'A backup is already running. Try again when it has finished.')
        ->and($ran)->toBeFalse();

    OtherSession::close();

    expect(app(BackupLock::class)->during(fn (): bool => true))->toBeTrue();
});

it('tells a file deletion a backup is running, whichever process runs it, and holds nothing after asking', function (): void {
    $lock = app(BackupLock::class);
    $mine = fn (): int => (int) DB::scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()");

    expect($lock->isHeld())->toBeFalse()
        ->and($mine())->toBe(0);

    OtherSession::holdBackupLock();

    expect($lock->isHeld())->toBeTrue()
        ->and($mine())->toBe(0);

    OtherSession::close();

    // In the very process that runs the backup, as a synchronous queue or teme:deploy would ask.
    expect($lock->during(fn (): bool => $lock->isHeld()))->toBeTrue()
        ->and($mine())->toBe(0);
});

it('refuses to nest, and still lets go', function (): void {
    $lock = app(BackupLock::class);

    expect(fn () => $lock->during(fn () => $lock->during(fn (): bool => true)))->toThrow(LogicException::class, 'This process already holds the backup lock.')
        ->and(OtherSession::backupRunning())->toBeFalse();
});

it('is made once per request or job', function (): void {
    $first = app(BackupLock::class);

    expect(app(BackupLock::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(BackupLock::class))->not->toBe($first);
});

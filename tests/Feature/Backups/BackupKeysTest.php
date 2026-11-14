<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Settings\SecretStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\ScriptedRestic;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

afterEach(function (): void {
    ($this->repository ?? null)?->delete();
});

it('makes the machine key once, keeps it encrypted in the backups group, and reuses it (I-359)', function (): void {
    $keys = app(BackupKeys::class);
    $key = $keys->ensureMachineKey(null);

    expect($key)->toMatch('/^[0-9a-f]{64}$/')
        ->and($keys->ensureMachineKey(null))->toBe($key)
        ->and($keys->machineKey())->toBe($key)
        ->and(app(SecretStore::class)->secret('backups', 'machine_key'))->toBe($key)
        ->and((string) DB::table('settings')->where('group_key', 'backups')->value('secrets'))->not->toContain($key)
        ->and(DB::table('settings_changes')->where('group_key', 'backups')->count())->toBe(1);
});

it('takes the server’s machine key when it supplies one, and stores nothing', function (): void {
    $variable = 'TEME_BACKUP_MACHINE_KEY';
    [$fromGetenv, $fromEnv, $fromServer] = [getenv($variable), $_ENV[$variable] ?? null, $_SERVER[$variable] ?? null];
    putenv("{$variable}=from-the-server");
    $_ENV[$variable] = $_SERVER[$variable] = 'from-the-server';

    try {
        expect(app(BackupKeys::class)->ensureMachineKey(null))->toBe('from-the-server')
            ->and(DB::table('settings')->where('group_key', 'backups')->value('secrets'))->toBeNull();
    } finally {
        $fromGetenv === false ? putenv($variable) : putenv("{$variable}={$fromGetenv}");
        $fromEnv === null ? (function () use ($variable): void {
            unset($_ENV[$variable]);
        })() : $_ENV[$variable] = $fromEnv;
        $fromServer === null ? (function () use ($variable): void {
            unset($_SERVER[$variable]);
        })() : $_SERVER[$variable] = $fromServer;
    }
});

it('says backups aren’t set up when there’s no machine key', function (): void {
    expect(fn () => app(BackupKeys::class)->machineKey())->toThrow(ValidationException::class, 'Backups aren’t set up: there’s no machine key.');
});

it('adds a key through restic’s standard input, and finds its ID', function (): void {
    $this->repository = TemporaryRepository::make();
    $keys = app(BackupKeys::class);
    $first = $keys->keyIds($this->repository->repository, 'Test');

    $added = $keys->add($this->repository->repository, 'a-second-key', 'Test');
    $openedWithIt = $keys->keyIds(new ResticRepository($this->repository->folder, 'a-second-key'), 'Test');

    expect($first['ids'])->toBe([$first['current']])
        ->and($added)->toMatch('/^[0-9a-f]{64}$/')->not->toBe($first['current'])
        ->and($keys->keyIds($this->repository->repository, 'Test')['ids'])->toEqualCanonicalizing([$first['current'], $added])
        ->and($openedWithIt['current'])->toBe($added)
        ->and($keys->opensWith(new ResticRepository($this->repository->folder, 'a-second-key'), 'Test'))->toBe($added)
        ->and($keys->opensWith(new ResticRepository($this->repository->folder, 'not-a-key-it-has'), 'Test'))->toBeNull();
});

it('removes only the keys Teme gave up on: never one in use, one without a row, or the one it opened with (I-359)', function (): void {
    $this->repository = TemporaryRepository::make();
    $keys = app(BackupKeys::class);
    $opened = $this->repository->repository;
    $place = BackupRecords::destination(['location' => ['path' => $this->repository->folder], 'repository_id' => $this->repository->id]);
    $machine = $keys->keyIds($opened, 'Test')['current'];
    $inUse = $keys->add($opened, 'a-key-in-use', 'Test');
    $pending = $keys->add($opened, 'a-key-nobody-typed-back', 'Test');
    $byHand = $keys->add($opened, 'a-key-added-outside-teme', 'Test');
    $at = now();
    BackupRecords::key($place, ['restic_key_id' => $machine]);
    BackupRecords::key($place, ['restic_key_id' => $inUse, 'role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    BackupRecords::key($place, ['restic_key_id' => $pending, 'role' => BackupKeyRole::Recovery, 'label' => 'Held by Ada', 'added_at' => $at, 'removed_at' => $at]);

    expect($keys->removeAbandoned($place, $opened))->toBe(1)
        ->and($keys->keyIds($opened, 'Test')['ids'])->toEqualCanonicalizing([$machine, $inUse, $byHand])
        ->and($keys->removeAbandoned($place, $opened))->toBe(0);
});

it('turns a failed restic call into the place’s words, and logs restic’s own without the key', function (): void {
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->level.' '.$message->message;
    });
    ScriptedRestic::exits(12, ScriptedRestic::exitError(12, 'Fatal: wrong password or no key found'));

    expect(fn () => app(BackupKeys::class)->keyIds(new ResticRepository('/var/backups/teme', 'the-wrong-key'), 'This server'))
        ->toThrow(ValidationException::class, 'The key didn’t open the backups at This server.')
        ->and($logged)->toBe(['warning Backups: reading the keys at This server: Fatal: wrong password or no key found']);
});

it('knows when setup is finished, and which recovery keys a place lacks (I-2401)', function (): void {
    $setup = app(BackupSetup::class);
    $first = BackupRecords::destination(['name' => 'This server', 'created_at' => now()->subDay()]);
    BackupRecords::key($first);

    expect($setup->isFinished())->toBeFalse()
        ->and($setup->unfinishedPlace()?->id)->toBe($first->id);

    BackupRecords::key($first, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    $second = BackupRecords::destination(['name' => 'Off-site', 'kind' => 's3']);
    BackupRecords::key($second);
    $gone = BackupRecords::destination(['state' => BackupDestinationState::Removed, 'removed_at' => now(), 'credentials' => null]);
    BackupRecords::key($gone, ['role' => BackupKeyRole::Recovery, 'label' => 'Old key']);
    BackupRecords::key($first, ['role' => BackupKeyRole::Recovery, 'label' => 'Was here', 'removed_at' => now()]);

    expect($setup->isFinished())->toBeTrue()
        ->and($setup->unfinishedPlace())->toBeNull()
        ->and($setup->placesInUse()->pluck('name')->all())->toBe(['This server', 'Off-site'])
        ->and($setup->recoveryKeyLabels())->toBe(['Recovery key'])
        ->and($setup->labelsMissingAt($second))->toBe(['Recovery key'])
        ->and($setup->labelsMissingAt($first))->toBe([])
        ->and(BackupSetup::suggestedFolder())->toBe(storage_path('app/backups'));
});

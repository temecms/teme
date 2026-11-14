<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Operations\AddBackupDestination;
use App\Domain\Backups\Operations\Input\AddBackupDestinationInput;
use App\Domain\Backups\PlaceAdded;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshHostKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\TemporaryRepository;
use Tests\Fixtures\Backups\WatchedProcesses;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->folders = [];
});

afterEach(function (): void {
    foreach ($this->folders as $folder) {
        TemporaryRepository::remove($folder);
    }
});

function adAdd(NewPlace $place, RecoveryKey $key): PlaceAdded
{
    return scElevated(fn (): PlaceAdded => app(AddBackupDestination::class)->execute(new AddBackupDestinationInput($place, $key)));
}

function adPolynomial(string $folder, string $key): string
{
    return (string) (app(Restic::class)->run(new ResticRepository($folder, $key), ['cat', 'config'])->json()['chunker_polynomial'] ?? '');
}

/** A site set up, as rows and as the scripted restic knows it: an S3 place made first, then a folder, both opened by the machine key and the recovery key. */
function adScriptedSite(WatchedProcesses $watched, RecoveryKey $recovery, bool $withFolder = true): void
{
    $machine = app(BackupKeys::class)->ensureMachineKey(null);
    $places = [BackupRecords::destination(['kind' => 's3', 'name' => 'Off-site', 'created_at' => now()->subDay()])];

    if ($withFolder) {
        $places[] = BackupRecords::destination(['name' => 'This server']);
    }

    foreach ($places as $place) {
        $machineId = BackupRecords::key($place)->restic_key_id;
        $recoveryId = BackupRecords::key($place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key'])->restic_key_id;
        $location = $place->kind->value === 's3' ? 's3:https://s3.example.com/teme-backups/site' : '/var/backups/teme';
        $watched->knows($location, $place->repository_id, [$machine => $machineId, $recovery->value() => $recoveryId]);
    }
}

it('adds a second folder, made with the first one’s chunker, locked with the machine key and the recovery key typed once (I-2402)', function (): void {
    $ready = ReadyBackups::atFolder();
    $second = ReadyBackups::folderPlace('Second place');
    $this->folders = [$ready->folder, (string) $second->location()['path']];

    $added = adAdd($second, RecoveryKey::fromTyped(strtolower($ready->recoveryKey->value())));
    $row = BackupDestination::query()->findOrFail($added->placeId);
    $machine = app(BackupKeys::class)->machineKey();
    $keys = $row->keys()->orderBy('role')->get();

    expect($added->labelsMissing)->toBe([])
        ->and($row->name)->toBe('Second place')
        ->and(adPolynomial($second->location()['path'], $machine))->toBe(adPolynomial($ready->folder, $machine))->not->toBe('')
        ->and($keys->pluck('role')->all())->toBe([BackupKeyRole::Machine, BackupKeyRole::Recovery])
        ->and($keys->pluck('removed_at')->all())->toBe([null, null])
        ->and($keys->last()->label)->toBe('Recovery key')
        ->and(app(BackupKeys::class)->opensWith(new ResticRepository($second->location()['path'], $ready->recoveryKey->value()), 'Test'))->toBe($keys->last()->restic_key_id)
        ->and(json_decode((string) DB::table('security_events')->where('type', 'backup.destination_added')->value('context'), true))
        ->toEqual(['destination' => $row->id, 'kind' => 'folder']);
});

it('refuses a recovery key that doesn’t open the backups already kept, and makes nothing', function (): void {
    $ready = ReadyBackups::atFolder();
    $second = ReadyBackups::folderPlace('Second place');
    $this->folders = [$ready->folder];

    expect(fn () => adAdd($second, RecoveryKey::generate()))
        ->toThrow(ValidationException::class, 'That recovery key doesn’t open the backups at This server.')
        ->and(is_dir((string) $second->location()['path']))->toBeFalse()
        ->and(BackupDestination::query()->count())->toBe(1);
});

it('refuses before setup is finished', function (): void {
    $first = ReadyBackups::folderPlace('This server');
    $this->folders = [(string) $first->location()['path']];
    $shown = ReadyBackups::setUp($first);

    expect(fn () => adAdd(ReadyBackups::folderPlace(), $shown->key))->toThrow(ValidationException::class, 'Finish setting backups up first.');
});

it('says which other recovery keys don’t open the new place yet', function (): void {
    $ready = ReadyBackups::atFolder();
    $second = ReadyBackups::folderPlace('Second place');
    $this->folders = [$ready->folder, (string) $second->location()['path']];
    $keys = app(BackupKeys::class);
    $adas = $keys->add(new ResticRepository($ready->folder, $keys->machineKey()), RecoveryKey::generate()->value(), 'This server');
    BackupRecords::key($ready->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Held by Ada', 'restic_key_id' => $adas]);

    expect(adAdd($second, $ready->recoveryKey)->labelsMissing)->toBe(['Held by Ada']);
});

it('takes the chunker from a place restic can open beside the new one', function (): void {
    $watched = WatchedProcesses::asRestic();
    $recovery = RecoveryKey::generate();
    adScriptedSite($watched, $recovery);

    adAdd(NewPlace::s3('Second account', 'https://s3.example.com', 'other-backups', null, null, 'AKIAOTHER', 'another-secret'), $recovery);

    expect($watched->restic('init')[0]['environment']['RESTIC_FROM_REPOSITORY'] ?? null)->toBe('/var/backups/teme')
        ->and($watched->restic('init')[0]['command'])->toContain('--copy-chunker-params');
});

it('refuses when no place in use can be opened beside the new one, before running anything', function (): void {
    $watched = WatchedProcesses::asRestic();
    adScriptedSite($watched, $recovery = RecoveryKey::generate(), withFolder: false);

    expect(fn () => adAdd(NewPlace::s3('Second account', 'https://s3.example.com', 'other-backups', null, null, 'AKIAOTHER', 'another-secret'), $recovery))
        ->toThrow(ValidationException::class, 'restic can’t open Second account beside the places already in use, because each needs its own S3 keys. Add a folder on this server first, then Second account.')
        ->and($watched->seen)->toBe([]);
});

it('refuses a name already in use', function (): void {
    $ready = ReadyBackups::atFolder();
    $this->folders = [$ready->folder];

    expect(fn () => adAdd(ReadyBackups::folderPlace('this SERVER'), $ready->recoveryKey))
        ->toThrow(ValidationException::class, 'Another place is already called this SERVER.');
});

it('adds an SFTP place through the call’s own SSH files, its key pair kept encrypted (I-2403)', function (): void {
    $watched = WatchedProcesses::asRestic();
    $recovery = RecoveryKey::generate();
    adScriptedSite($watched, $recovery);
    $pair = SftpKeyPair::generate();
    $place = NewPlace::sftp('Storage box', 'backup.example.com', 23, 'u123', '/backups/teme', $pair,
        SshHostKey::fromLine('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl'));

    adAdd($place, $recovery);
    $init = $watched->restic('init')[0];

    expect($init['environment']['RESTIC_REPOSITORY'])->toBe("sftp:teme-{$place->id}:/backups/teme")
        ->and(implode(' ', $init['command']))->toContain("sftp.args=-F '")
        ->and(BackupDestination::query()->findOrFail($place->id)->credentials)->toBe(['private_key' => $pair->privateKey()])
        ->and(BackupDestination::query()->findOrFail($place->id)->off_server)->toBeTrue();
});

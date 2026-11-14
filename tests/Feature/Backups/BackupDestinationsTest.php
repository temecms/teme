<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Operations\AddBackupDestination;
use App\Domain\Backups\Operations\ChangeBackupDestination;
use App\Domain\Backups\Operations\Input\AddBackupDestinationInput;
use App\Domain\Backups\Operations\Input\ChangeBackupDestinationInput;
use App\Domain\Backups\Operations\Input\RemoveBackupDestinationInput;
use App\Domain\Backups\Operations\Input\TestBackupDestinationInput;
use App\Domain\Backups\Operations\RemoveBackupDestination;
use App\Domain\Backups\Operations\TestBackupDestination;
use App\Domain\Backups\PlaceAnswer;
use App\Domain\Backups\Tools\ResticOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\ScriptedRestic;
use Tests\Fixtures\Backups\TemporaryRepository;
use Tests\Fixtures\Backups\WatchedProcesses;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->ready = ReadyBackups::atFolder();
    $this->folders = [$this->ready->folder];
});

afterEach(function (): void {
    foreach ($this->folders as $folder) {
        TemporaryRepository::remove($folder);
    }
});

function bdRun(object $operation, object $input): mixed
{
    return scElevated(fn (): mixed => $operation->execute($input));
}

function bdTest(BackupDestination $place): PlaceAnswer
{
    return bdRun(app(TestBackupDestination::class), new TestBackupDestinationInput($place->id));
}

it('renames a place without opening it', function (): void {
    $watched = WatchedProcesses::runForReal();

    bdRun(app(ChangeBackupDestination::class), new ChangeBackupDestinationInput($this->ready->place->id, 'The server itself', $this->ready->place->location));

    expect($this->ready->place->fresh()->name)->toBe('The server itself')
        ->and($watched->seen)->toBe([])
        ->and(json_decode((string) DB::table('security_events')->where('type', 'backup.destination_changed')->value('context'), true))
        ->toEqual(['destination' => $this->ready->place->id, 'fields' => ['name']]);
});

it('follows a folder that moved, and refuses an address that holds other backups (I-360)', function (): void {
    $moved = TemporaryRepository::freshFolder();
    rename($this->ready->folder, $moved);
    $this->folders = [$moved];
    $other = TemporaryRepository::make(app(BackupKeys::class)->machineKey());
    $this->folders[] = $other->folder;

    bdRun(app(ChangeBackupDestination::class), new ChangeBackupDestinationInput($this->ready->place->id, 'This server', ['path' => $moved]));

    expect($this->ready->place->fresh()->location)->toBe(['path' => $moved])
        ->and(fn () => bdRun(app(ChangeBackupDestination::class), new ChangeBackupDestinationInput($this->ready->place->id, 'This server', ['path' => $other->folder])))
        ->toThrow(ValidationException::class, 'That address holds other backups, not This server’s.')
        ->and($this->ready->place->fresh()->location)->toBe(['path' => $moved]);
});

it('reports a folder that answers, opens and has room', function (): void {
    $answer = bdTest($this->ready->place);
    $row = $this->ready->place->fresh();

    expect($answer->answered)->toBeTrue()
        ->and($answer->opened)->toBeTrue()
        ->and($answer->freeBytes)->toBeGreaterThan(0)
        ->and($answer->sentence)->toBe('This server answered, and Teme’s key opened its backups. '.Number::fileSize((int) $answer->freeBytes, 1).' free on its disk.')
        ->and($row->state)->toBe(BackupDestinationState::Ready)
        ->and($row->last_error)->toBeNull();
});

it('marks a place failing, in words, when its backups are gone or it times out', function (): void {
    $answeredAt = $this->ready->place->last_answered_at;
    TemporaryRepository::remove($this->ready->folder);

    $gone = bdTest($this->ready->place);

    expect($gone->answered)->toBeFalse()
        ->and($gone->sentence)->toBe(ResticOutcome::NoRepository->sentence('This server'))
        ->and($this->ready->place->fresh()->state)->toBe(BackupDestinationState::Failing)
        ->and($this->ready->place->fresh()->last_error)->toBe($gone->sentence)
        ->and($this->ready->place->fresh()->last_answered_at?->equalTo($answeredAt))->toBeTrue();

    ScriptedRestic::timesOut();

    expect(bdTest($this->ready->place)->sentence)->toBe('The backup tool took longer than 1 minute at This server, so Teme stopped it.');
});

it('says when another repository is at a place’s address', function (): void {
    $other = TemporaryRepository::make(app(BackupKeys::class)->machineKey());
    $this->folders[] = $other->folder;
    DB::table('backup_destinations')->where('id', $this->ready->place->id)->update(['location' => json_encode(['path' => $other->folder])]);

    $answer = bdTest($this->ready->place->fresh());

    expect($answer->answered)->toBeTrue()
        ->and($answer->opened)->toBeFalse()
        ->and($answer->sentence)->toBe('This server holds other backups than the ones Teme made there.')
        ->and($this->ready->place->fresh()->state)->toBe(BackupDestinationState::Failing);
});

it('removes a place: Teme stops using it, its backups stay, and it keeps no secret (I-360)', function (): void {
    $second = ReadyBackups::folderPlace('Second place');
    $this->folders[] = (string) $second->location()['path'];
    $added = bdRun(app(AddBackupDestination::class), new AddBackupDestinationInput($second, $this->ready->recoveryKey));

    bdRun(app(RemoveBackupDestination::class), new RemoveBackupDestinationInput($added->placeId));
    $row = BackupDestination::query()->findOrFail($added->placeId);

    expect($row->state)->toBe(BackupDestinationState::Removed)
        ->and($row->removed_at)->not->toBeNull()
        ->and($row->credentials)->toBeNull()
        ->and(is_file($second->location()['path'].DIRECTORY_SEPARATOR.'config'))->toBeTrue()
        ->and(json_decode((string) DB::table('security_events')->where('type', 'backup.destination_removed')->value('context'), true))
        ->toEqual(['destination' => $added->placeId, 'kind' => 'folder'])
        ->and(fn () => bdTest($row))->toThrow(ValidationException::class, 'There’s no such place.');
});

it('asks for a confirmed key when an SFTP server moves', function (): void {
    $place = BackupRecords::destination(['kind' => 'sftp', 'name' => 'Storage box', 'location' => [
        'host' => 'backup.example.com', 'port' => 23, 'user' => 'u123', 'path' => '/backups/teme',
        'host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl',
    ]]);
    $moved = ['host' => 'backup2.example.com', 'port' => 23, 'user' => 'u123', 'path' => '/backups/teme'];
    $watched = WatchedProcesses::runForReal();

    expect(fn () => bdRun(app(ChangeBackupDestination::class), new ChangeBackupDestinationInput($place->id, 'Storage box', $moved)))
        ->toThrow(ValidationException::class, 'Confirm the server’s fingerprint first.')
        ->and($watched->seen)->toBe([])
        ->and($place->fresh()->location['host'])->toBe('backup.example.com');
});

it('won’t remove the only place once backups are set up, but will while setup is unfinished (I-2401)', function (): void {
    expect(fn () => bdRun(app(RemoveBackupDestination::class), new RemoveBackupDestinationInput($this->ready->place->id)))
        ->toThrow(ValidationException::class, 'This server is the only place backups are kept. Add another place before removing it.');

    DB::table('backup_keys')->where('role', 'recovery')->update(['removed_at' => now()]);

    bdRun(app(RemoveBackupDestination::class), new RemoveBackupDestinationInput($this->ready->place->id));
    $again = ReadyBackups::folderPlace('Somewhere else');
    $this->folders[] = (string) $again->location()['path'];

    expect($this->ready->place->fresh()->state)->toBe(BackupDestinationState::Removed)
        ->and(ReadyBackups::setUp($again)->challenge->pending)->toHaveKey($again->id);
});

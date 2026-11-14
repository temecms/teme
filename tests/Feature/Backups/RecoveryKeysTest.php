<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Operations\AddBackupDestination;
use App\Domain\Backups\Operations\AddRecoveryKey;
use App\Domain\Backups\Operations\CheckRecoveryKey;
use App\Domain\Backups\Operations\ExtendRecoveryKey;
use App\Domain\Backups\Operations\Input\AddBackupDestinationInput;
use App\Domain\Backups\Operations\Input\AddRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\CheckRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ExtendRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ReplaceRecoveryKeyInput;
use App\Domain\Backups\Operations\ReplaceRecoveryKey;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Backups\RecoveryKeyCheck;
use App\Domain\Backups\RecoveryKeyConfirmed;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->ready = ReadyBackups::atFolder();
    $this->second = ReadyBackups::folderPlace('Second place');
    $this->folders = [$this->ready->folder, (string) $this->second->location()['path']];
});

afterEach(function (): void {
    foreach ($this->folders as $folder) {
        TemporaryRepository::remove($folder);
    }
});

function rkRun(object $operation, object $input): mixed
{
    return scElevated(fn (): mixed => $operation->execute($input));
}

/** The restic ID $key opens $folder with, or null. */
function rkOpens(string $folder, RecoveryKey $key): ?string
{
    return app(BackupKeys::class)->opensWith(new ResticRepository($folder, $key->value()), 'Test');
}

it('adds a recovery key to every place, recorded once it is typed back (I-359)', function (): void {
    rkRun(app(AddBackupDestination::class), new AddBackupDestinationInput($this->second, $this->ready->recoveryKey));

    $shown = rkRun(app(AddRecoveryKey::class), new AddRecoveryKeyInput('Held by Ada'));
    $opens = [rkOpens($this->ready->folder, $shown->key), rkOpens($this->folders[1], $shown->key)];

    expect($opens)->not->toContain(null)
        ->and(array_values($shown->challenge->pending))->toEqualCanonicalizing($opens)
        ->and(BackupKey::query()->where('label', 'Held by Ada')->whereNull('removed_at')->exists())->toBeFalse();

    expect(ReadyBackups::confirm($shown))->toEqual(new RecoveryKeyConfirmed('Held by Ada', 2))
        ->and(BackupKey::query()->where('label', 'Held by Ada')->where('role', BackupKeyRole::Recovery)->whereNull('removed_at')->count())->toBe(2);
});

it('refuses a label already in use, or none', function (): void {
    expect(fn () => rkRun(app(AddRecoveryKey::class), new AddRecoveryKeyInput('recovery KEY')))
        ->toThrow(ValidationException::class, 'Another recovery key is already called recovery KEY.')
        ->and(fn () => rkRun(app(AddRecoveryKey::class), new AddRecoveryKeyInput('  ')))
        ->toThrow(ValidationException::class, 'Give the key a name of up to 100 characters, such as who holds it.');
});

it('sweeps a key nobody confirmed before showing another', function (): void {
    $first = rkRun(app(AddRecoveryKey::class), new AddRecoveryKeyInput('Held by Ada'));
    rkRun(app(AddRecoveryKey::class), new AddRecoveryKeyInput('Held by Bea'));

    expect(rkOpens($this->ready->folder, $first->key))->toBeNull()
        ->and(fn () => ReadyBackups::confirm($first))
        ->toThrow(ValidationException::class, 'This key was replaced, or the places changed, before it was confirmed. Start again to see a new key.');
});

it('extends a recovery key to the places it doesn’t open yet (I-2402)', function (): void {
    $keys = app(BackupKeys::class);
    $adas = RecoveryKey::generate();
    $adasId = $keys->add(new ResticRepository($this->ready->folder, $keys->machineKey()), $adas->value(), 'This server');
    BackupRecords::key($this->ready->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Held by Ada', 'restic_key_id' => $adasId]);
    rkRun(app(AddBackupDestination::class), new AddBackupDestinationInput($this->second, $this->ready->recoveryKey));

    $extended = rkRun(app(ExtendRecoveryKey::class), new ExtendRecoveryKeyInput($adas));

    expect($extended)->toBe(['label' => 'Held by Ada', 'places' => ['Second place']])
        ->and(rkOpens($this->folders[1], $adas))->toBe(BackupKey::query()->where('destination_id', $this->second->id)->where('label', 'Held by Ada')->value('restic_key_id'))
        ->and(json_decode((string) DB::table('security_events')->where('type', 'backup.recovery_key_added')->latest('id')->value('context'), true))
        ->toEqual(['label' => 'Held by Ada', 'places' => 1]);
});

it('refuses to extend a key that opens nothing, or already opens everywhere', function (): void {
    expect(fn () => rkRun(app(ExtendRecoveryKey::class), new ExtendRecoveryKeyInput(RecoveryKey::generate())))
        ->toThrow(ValidationException::class, 'That recovery key doesn’t open any place backups are kept.')
        ->and(fn () => rkRun(app(ExtendRecoveryKey::class), new ExtendRecoveryKeyInput($this->ready->recoveryKey)))
        ->toThrow(ValidationException::class, 'Recovery key already opens every place backups are kept.');
});

it('keeps the old key until the new one is typed back, then removes it everywhere, and the backups stay readable (I-359)', function (): void {
    $restic = app(Restic::class);
    $machine = app(BackupKeys::class)->machineKey();
    $restic->run(new ResticRepository($this->ready->folder, $machine), ['backup', '--stdin', '--stdin-filename', 'note.txt'], input: 'Kept before the key changed.');

    $shown = rkRun(app(ReplaceRecoveryKey::class), new ReplaceRecoveryKeyInput('Recovery key'));

    expect(rkOpens($this->ready->folder, $this->ready->recoveryKey))->not->toBeNull()
        ->and($shown->challenge->label)->toBe('Recovery key');

    expect(ReadyBackups::confirm($shown))->toEqual(new RecoveryKeyConfirmed('Recovery key', 1))
        ->and(rkOpens($this->ready->folder, $this->ready->recoveryKey))->toBeNull()
        ->and(rkOpens($this->ready->folder, $shown->key))->not->toBeNull()
        ->and($restic->run(new ResticRepository($this->ready->folder, $shown->key->value()), ['snapshots'])->json())->toHaveCount(1)
        ->and(BackupKey::query()->where('role', BackupKeyRole::Recovery)->whereNotNull('removed_at')->count())->toBe(1)
        ->and(BackupKey::query()->where('role', BackupKeyRole::Recovery)->whereNull('removed_at')->value('restic_key_id'))->toBe(rkOpens($this->ready->folder, $shown->key))
        ->and(DB::table('security_events')->where('type', 'backup.recovery_key_replaced')->exists())->toBeTrue();
});

it('refuses to replace a key it doesn’t know', function (): void {
    expect(fn () => rkRun(app(ReplaceRecoveryKey::class), new ReplaceRecoveryKeyInput('Nobody’s')))
        ->toThrow(ValidationException::class, 'There’s no recovery key called Nobody’s.');
});

it('checks a recovery key at every place, keeping nothing of it', function (): void {
    rkRun(app(AddBackupDestination::class), new AddBackupDestinationInput($this->second, $this->ready->recoveryKey));
    $before = BackupKey::query()->orderBy('id')->get()->toArray();

    $right = rkRun(app(CheckRecoveryKey::class), new CheckRecoveryKeyInput(RecoveryKey::fromTyped(strtolower($this->ready->recoveryKey->value()))));
    $wrong = rkRun(app(CheckRecoveryKey::class), new CheckRecoveryKeyInput(RecoveryKey::generate()));
    $logged = DB::table('security_events')->where('type', 'backup.recovery_key_checked')->orderBy('id')->pluck('context')
        ->map(fn (string $context): array => json_decode($context, true))->all();

    expect($right)->toEqual(new RecoveryKeyCheck('Recovery key', ['This server', 'Second place'], []))
        ->and($wrong)->toEqual(new RecoveryKeyCheck(null, [], ['This server', 'Second place']))
        ->and(BackupKey::query()->orderBy('id')->get()->toArray())->toBe($before)
        ->and($logged)->toEqual([['label' => 'Recovery key', 'opened' => 2, 'of' => 2], ['label' => null, 'opened' => 0, 'of' => 2]]);
});

<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Operations\ConfirmRecoveryKey;
use App\Domain\Backups\Operations\Input\ConfirmRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\SetUpBackupsInput;
use App\Domain\Backups\Operations\SetUpBackups;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\RecoveryKeyConfirmed;
use App\Domain\Backups\ShownRecoveryKey;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->place = ReadyBackups::folderPlace('This server');
    $this->shown = ReadyBackups::setUp($this->place);
});

afterEach(function (): void {
    TemporaryRepository::remove((string) $this->place->location()['path']);
});

/** The two groups asked, as a person types them, or swapped when $wrong. */
function crGroups(ShownRecoveryKey $shown, bool $wrong = false): array
{
    [$a, $b] = $shown->challenge->positions;

    return $wrong ? [$a => $shown->key->group($b), $b => $shown->key->group($a)] : [$a => strtolower($shown->key->group($a)), $b => ' '.$shown->key->group($b)];
}

it('finishes setup when the two groups are typed back (I-2401)', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $done = scElevated(fn (): RecoveryKeyConfirmed => app(ConfirmRecoveryKey::class)->execute(new ConfirmRecoveryKeyInput($this->shown->challenge, crGroups($this->shown), (string) $admin->id)));
    $row = BackupKey::query()->where('role', BackupKeyRole::Recovery)->sole();

    expect($done)->toEqual(new RecoveryKeyConfirmed('Recovery key', 1))
        ->and($row->removed_at)->toBeNull()
        ->and($row->destination_id)->toBe($this->place->id)
        ->and($row->restic_key_id)->toBe($this->shown->challenge->pending[$this->place->id])
        ->and($row->label)->toBe('Recovery key')
        ->and($row->added_by)->toBe($admin->id)
        ->and(app(BackupSetup::class)->isFinished())->toBeTrue()
        ->and(json_decode((string) DB::table('security_events')->where('type', 'backup.recovery_key_added')->value('context'), true))
        ->toEqual(['label' => 'Recovery key', 'places' => 1]);
});

it('refuses the wrong groups, and records nothing', function (): void {
    [$a, $b] = $this->shown->challenge->positions;

    expect(fn () => scElevated(fn () => app(ConfirmRecoveryKey::class)->execute(new ConfirmRecoveryKeyInput($this->shown->challenge, crGroups($this->shown, wrong: true)))))
        ->toThrow(ValidationException::class, "Those aren’t groups {$a} and {$b} of the recovery key. Check the key you wrote down, and type them again.")
        ->and(BackupKey::query()->where('role', BackupKeyRole::Recovery)->whereNull('removed_at')->exists())->toBeFalse()
        ->and(app(BackupSetup::class)->isFinished())->toBeFalse();
});

it('refuses a key that was swept before it was confirmed', function (): void {
    scElevated(fn (): ShownRecoveryKey => app(SetUpBackups::class)->execute(new SetUpBackupsInput(null)));

    expect(fn () => ReadyBackups::confirm($this->shown))
        ->toThrow(ValidationException::class, 'This key was replaced, or the places changed, before it was confirmed. Start again to see a new key.')
        ->and(BackupKey::query()->where('role', BackupKeyRole::Recovery)->whereNull('removed_at')->exists())->toBeFalse();
});

it('writes nothing when authorization fails', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $admin = User::factory()->create()->assignRole('administrator');
    $input = fn (?string $actorId): ConfirmRecoveryKeyInput => new ConfirmRecoveryKeyInput($this->shown->challenge, crGroups($this->shown), $actorId);

    expect(fn () => scElevated(fn () => app(ConfirmRecoveryKey::class)->execute($input((string) $editor->id))))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ConfirmRecoveryKey::class)->execute($input(null)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ConfirmRecoveryKey::class)->execute($input((string) $admin->id)))->toThrow(ElevationRequiredException::class)
        ->and(BackupKey::query()->where('role', BackupKeyRole::Recovery)->whereNull('removed_at')->exists())->toBeFalse()
        ->and(DB::table('security_events')->where('type', 'backup.recovery_key_added')->exists())->toBeFalse();
});

it('refuses to confirm twice, or to set backups up again once they are', function (): void {
    ReadyBackups::confirm($this->shown);

    expect(fn () => ReadyBackups::confirm($this->shown))
        ->toThrow(ValidationException::class, 'This key was replaced, or the places changed, before it was confirmed. Start again to see a new key.')
        ->and(fn () => ReadyBackups::setUp(ReadyBackups::folderPlace()))
        ->toThrow(ValidationException::class, 'Backups are already set up.')
        ->and(BackupKey::query()->where('role', BackupKeyRole::Recovery)->whereNull('removed_at')->count())->toBe(1);
});

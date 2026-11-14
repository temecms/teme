<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Operations\AddBackupDestination;
use App\Domain\Backups\Operations\AddRecoveryKey;
use App\Domain\Backups\Operations\ChangeBackupDestination;
use App\Domain\Backups\Operations\CheckRecoveryKey;
use App\Domain\Backups\Operations\ExtendRecoveryKey;
use App\Domain\Backups\Operations\Input\AddBackupDestinationInput;
use App\Domain\Backups\Operations\Input\AddRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ChangeBackupDestinationInput;
use App\Domain\Backups\Operations\Input\CheckRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ExtendRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\RemoveBackupDestinationInput;
use App\Domain\Backups\Operations\Input\ReplaceRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\TestBackupDestinationInput;
use App\Domain\Backups\Operations\RemoveBackupDestination;
use App\Domain\Backups\Operations\ReplaceRecoveryKey;
use App\Domain\Backups\Operations\TestBackupDestination;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\TemporaryRepository;
use Tests\Fixtures\Backups\WatchedProcesses;

uses(RefreshDatabase::class);

/** Everything a backup Operation could write. */
function oaState(): string
{
    return json_encode([
        DB::table('backup_destinations')->orderBy('id')->get(),
        DB::table('backup_keys')->orderBy('id')->get(),
        DB::table('security_events')->orderBy('id')->get(),
        DB::table('settings')->orderBy('group_key')->get(),
    ], JSON_THROW_ON_ERROR);
}

it('writes nothing, and runs nothing, when authorization fails', function (Closure $operation): void {
    $this->seed(RoleSeeder::class);
    $place = BackupRecords::destination(['name' => 'This server']);
    BackupRecords::key($place);
    BackupRecords::key($place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    $editor = User::factory()->create()->assignRole('editor');
    $admin = User::factory()->create()->assignRole('administrator');
    $watched = WatchedProcesses::runForReal();
    $before = oaState();

    [$class, $input] = $operation($place, (string) $editor->id);
    expect(fn () => app($class)->execute($input))->toThrow(AuthorizationException::class, 'Only an administrator manages backups.');

    [$class, $input] = $operation($place, null);
    expect(fn () => app($class)->execute($input))->toThrow(AuthorizationException::class);

    [$class, $input] = $operation($place, (string) $admin->id);
    expect(fn () => app($class)->execute($input))->toThrow(ElevationRequiredException::class)
        ->and(oaState())->toBe($before)
        ->and($watched->seen)->toBe([]);
})->with([
    'adding a place' => fn (BackupDestination $place, ?string $actor): array => [AddBackupDestination::class, new AddBackupDestinationInput(NewPlace::folder('Second place', TemporaryRepository::freshFolder()), RecoveryKey::generate(), $actor)],
    'changing a place' => fn (BackupDestination $place, ?string $actor): array => [ChangeBackupDestination::class, new ChangeBackupDestinationInput($place->id, 'Renamed', $place->location, null, $actor)],
    'testing a place' => fn (BackupDestination $place, ?string $actor): array => [TestBackupDestination::class, new TestBackupDestinationInput($place->id, $actor)],
    'removing a place' => fn (BackupDestination $place, ?string $actor): array => [RemoveBackupDestination::class, new RemoveBackupDestinationInput($place->id, $actor)],
    'adding a recovery key' => fn (BackupDestination $place, ?string $actor): array => [AddRecoveryKey::class, new AddRecoveryKeyInput('Held by Ada', $actor)],
    'extending a recovery key' => fn (BackupDestination $place, ?string $actor): array => [ExtendRecoveryKey::class, new ExtendRecoveryKeyInput(RecoveryKey::generate(), $actor)],
    'replacing a recovery key' => fn (BackupDestination $place, ?string $actor): array => [ReplaceRecoveryKey::class, new ReplaceRecoveryKeyInput('Recovery key', $actor)],
    'checking a recovery key' => fn (BackupDestination $place, ?string $actor): array => [CheckRecoveryKey::class, new CheckRecoveryKeyInput(RecoveryKey::generate(), $actor)],
]);

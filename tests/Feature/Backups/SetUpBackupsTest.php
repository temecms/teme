<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Operations\Input\SetUpBackupsInput;
use App\Domain\Backups\Operations\SetUpBackups;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\ShownRecoveryKey;
use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshHostKey;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\TemporaryRepository;
use Tests\Fixtures\Backups\WatchedProcesses;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->folders = [];
});

afterEach(function (): void {
    foreach ($this->folders as $folder) {
        TemporaryRepository::remove($folder);
    }
});

/** Nothing of a setup was written: no place, no key, no machine key, no log entry. */
function suNothingWritten(): void
{
    expect(BackupDestination::query()->count())->toBe(0)
        ->and(BackupKey::query()->count())->toBe(0)
        ->and(DB::table('settings')->where('group_key', 'backups')->whereNotNull('secrets')->exists())->toBeFalse()
        ->and(DB::table('security_events')->where('type', 'backup.set_up')->exists())->toBeFalse();
}

function suOpens(NewPlace|BackupDestination $place, string $key): string
{
    $path = $place instanceof NewPlace ? $place->location()['path'] : $place->location['path'];

    return app(BackupKeys::class)->keyIds(new ResticRepository((string) $path, $key), 'Test')['current'];
}

it('sets backups up at a folder: the machine key opens it, a recovery key is shown once, and setup isn’t finished yet (I-2401)', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $place = ReadyBackups::folderPlace('This server');
    $this->folders[] = $place->location()['path'];

    $shown = ReadyBackups::setUp($place, (string) $admin->id);
    $row = BackupDestination::query()->findOrFail($place->id);
    $machine = app(BackupKeys::class)->machineKey();

    expect($shown)->toBeInstanceOf(ShownRecoveryKey::class)
        ->and($row->kind)->toBe(BackupDestinationKind::Folder)
        ->and($row->name)->toBe('This server')
        ->and($row->credentials)->toBeNull()
        ->and($row->off_server)->toBeFalse()
        ->and($row->state)->toBe(BackupDestinationState::Ready)
        ->and($row->added_by)->toBe($admin->id)
        ->and($row->last_answered_at)->not->toBeNull()
        ->and($row->keys()->whereNull('removed_at')->pluck('restic_key_id')->all())->toBe([suOpens($row, $machine)])
        ->and($row->keys()->whereNull('removed_at')->first()?->role)->toBe(BackupKeyRole::Machine)
        ->and($row->keys()->whereNull('removed_at')->first()?->label)->toBe('Machine key')
        // The shown key's row is pending: removed_at equal to added_at until it is typed back (D6).
        ->and($row->keys()->where('role', BackupKeyRole::Recovery)->sole()->restic_key_id)->toBe($shown->challenge->pending[$row->id])
        ->and($row->keys()->where('role', BackupKeyRole::Recovery)->sole()->removed_at?->equalTo($row->keys()->where('role', BackupKeyRole::Recovery)->sole()->added_at))->toBeTrue()
        ->and(suOpens($row, $shown->key->value()))->toBe($shown->challenge->pending[$row->id])
        ->and($shown->challenge->label)->toBe('Recovery key')
        ->and(app(BackupSetup::class)->isFinished())->toBeFalse()
        ->and(json_decode((string) DB::table('security_events')->where('type', 'backup.set_up')->value('context'), true))
        ->toEqual(['destination' => $row->id, 'kind' => 'folder']);
});

it('writes nothing when authorization fails', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $place = ReadyBackups::folderPlace('This server');

    expect(fn () => ReadyBackups::setUp($place, (string) $editor->id))->toThrow(AuthorizationException::class, 'Only an administrator manages backups.')
        ->and(fn () => app(SetUpBackups::class)->execute(new SetUpBackupsInput($place)))->toThrow(AuthorizationException::class);

    suNothingWritten();
    expect(is_dir($place->location()['path']))->toBeFalse();
});

it('asks for the password again before it makes anything', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $place = ReadyBackups::folderPlace('This server');

    expect(fn () => app(SetUpBackups::class)->execute(new SetUpBackupsInput($place, actorId: (string) $admin->id)))
        ->toThrow(ElevationRequiredException::class);

    suNothingWritten();
    expect(is_dir($place->location()['path']))->toBeFalse();
});

it('asks for a place when there’s none, and refuses another while setup is unfinished', function (): void {
    $first = ReadyBackups::folderPlace('This server');
    $this->folders[] = $first->location()['path'];

    expect(fn () => scElevated(fn () => app(SetUpBackups::class)->execute(new SetUpBackupsInput(null))))
        ->toThrow(ValidationException::class, 'Choose the first place to keep backups.');

    ReadyBackups::setUp($first);

    expect(fn () => ReadyBackups::setUp(ReadyBackups::folderPlace()))
        ->toThrow(ValidationException::class, 'Backups are being set up at This server. Finish that, or remove This server to start again.');
});

it('refuses a folder that already holds backups, and writes nothing', function (): void {
    $existing = TemporaryRepository::make();
    $this->folders[] = $existing->folder;

    expect(fn () => ReadyBackups::setUp(NewPlace::folder('This server', $existing->folder)))
        ->toThrow(ValidationException::class, "There are already backups in {$existing->folder}. Choose an empty folder.");

    suNothingWritten();
});

it('a second setup keeps its place, removes the key nobody saw, and shows a new one (I-2401)', function (): void {
    $place = ReadyBackups::folderPlace('This server');
    $this->folders[] = $place->location()['path'];
    $first = ReadyBackups::setUp($place);
    // A key an operator added with restic by hand: no row, so never swept (D8).
    $keys = app(BackupKeys::class);
    $byHand = $keys->add(new ResticRepository((string) $place->location()['path'], $keys->machineKey()), 'an-operators-own-key', 'Test');

    $second = scElevated(fn (): ShownRecoveryKey => app(SetUpBackups::class)->execute(new SetUpBackupsInput(null)));

    expect($second->key->value())->not->toBe($first->key->value())
        ->and(suOpens($place, $second->key->value()))->toBe($second->challenge->pending[$place->id])
        ->and(fn () => suOpens($place, $first->key->value()))->toThrow(ValidationException::class, 'The key didn’t open the backups at Test.')
        ->and(suOpens($place, 'an-operators-own-key'))->toBe($byHand)
        ->and(BackupDestination::query()->count())->toBe(1)
        ->and(BackupKey::query()->whereNull('removed_at')->count())->toBe(1);
});

it('lets one first setup through when two pass their checks together, and makes one machine key', function (): void {
    $first = ReadyBackups::folderPlace('This server');
    $other = ReadyBackups::folderPlace('Other server');
    $this->folders = [(string) $first->location()['path'], (string) $other->location()['path']];
    $nested = false;

    // The moment the first holds the key lock, a second setup runs to the end inside it, as one
    // that passed validate() at the same instant would; the first must then see it, and refuse.
    DB::listen(function (QueryExecuted $query) use (&$nested, $other): void {
        if (! $nested && str_contains($query->sql, 'teme.backup-keys')) {
            $nested = true;
            ReadyBackups::setUp($other);
        }
    });

    expect(fn () => ReadyBackups::setUp($first))
        ->toThrow(ValidationException::class, 'Backups are being set up at Other server. Finish that, or remove Other server to start again.')
        ->and($nested)->toBeTrue();
});

it('sets up at S3 with its keys in restic’s environment only, and keeps them encrypted', function (): void {
    $watched = WatchedProcesses::asRestic();
    $place = NewPlace::s3('Off-site', 'https://s3.example.test', 'teme-backups', 'site', null, 'AKIATEST', 'secret-test-value');

    ReadyBackups::setUp($place);
    $row = BackupDestination::query()->findOrFail($place->id);
    $init = $watched->restic('init')[0];

    expect($row->credentials)->toBe(['access_key_id' => 'AKIATEST', 'secret_access_key' => 'secret-test-value'])
        ->and($row->off_server)->toBeTrue()
        ->and((string) DB::table('backup_destinations')->where('id', $row->id)->value('credentials'))->not->toContain('secret-test-value')
        ->and($row->toJson())->not->toContain('secret-test-value')
        ->and(implode(' ', $watched->arguments()))->not->toContain('secret-test-value')
        ->and($init['environment']['RESTIC_REPOSITORY'])->toBe('s3:https://s3.example.test/teme-backups/site')
        ->and($init['environment']['AWS_SECRET_ACCESS_KEY'])->toBe('secret-test-value');
});

it('sets up at SFTP through the call’s own SSH files, and keeps its key pair encrypted (I-2403)', function (): void {
    $watched = WatchedProcesses::asRestic();
    $pair = SftpKeyPair::generate();
    $hostKey = SshHostKey::fromLine('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl');
    $place = NewPlace::sftp('Storage box', 'backup.example.com', 23, 'u123', '/backups/teme', $pair, $hostKey);

    ReadyBackups::setUp($place);
    $row = BackupDestination::query()->findOrFail($place->id);
    $init = $watched->restic('init')[0];
    preg_match("#sftp\.args=-F '(.+)/ssh_config'#", implode(' ', $init['command']), $match);

    expect($init['environment']['RESTIC_REPOSITORY'])->toBe("sftp:teme-{$place->id}:/backups/teme")
        ->and($match[1] ?? '')->not->toBe('')
        ->and(is_dir($match[1] ?? ''))->toBeFalse()
        ->and(SftpKeyPair::fromPrivateKey($row->credentials['private_key'] ?? '')->publicKey())->toBe($pair->publicKey())
        ->and($row->location['host_key'])->toBe($hostKey->line())
        ->and($row->location['fingerprint'])->toBe($hostKey->fingerprint());
});

it('refuses an SFTP server that doesn’t answer, and writes nothing', function (): void {
    $place = NewPlace::sftp('Storage box', '127.0.0.1', 9, 'teme', '/backups/teme', SftpKeyPair::generate(),
        SshHostKey::fromLine('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl'));

    expect(fn () => ReadyBackups::setUp($place))->toThrow(ValidationException::class, ResticOutcome::Failed->sentence('Storage box'));

    suNothingWritten();
});

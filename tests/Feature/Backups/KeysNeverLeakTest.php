<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Operations\AddBackupDestination;
use App\Domain\Backups\Operations\AddRecoveryKey;
use App\Domain\Backups\Operations\CheckRecoveryKey;
use App\Domain\Backups\Operations\Input\AddBackupDestinationInput;
use App\Domain\Backups\Operations\Input\AddRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\CheckRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ReplaceRecoveryKeyInput;
use App\Domain\Backups\Operations\ReplaceRecoveryKey;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshHostKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\FixedRecoveryKeys;
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

/**
 * Every text and JSON column of every table that holds any of $secrets, as table.column.
 *
 * @param  list<string>  $secrets
 *
 * @return list<string>
 */
function klInTheClear(array $secrets): array
{
    $found = [];
    $columns = DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = current_schema() AND data_type IN ('text', 'character varying', 'character', 'json', 'jsonb')");

    foreach ($columns as $column) {
        $wrapped = DB::getQueryGrammar()->wrap($column->column_name);

        foreach ($secrets as $secret) {
            if (DB::table($column->table_name)->whereRaw("strpos({$wrapped}::text, ?) > 0", [$secret])->exists()) {
                $found[] = "{$column->table_name}.{$column->column_name}";
            }
        }
    }

    return $found;
}

/** A place of $kind, and the secret its credentials hold. */
function klPlace(string $kind): array
{
    if ($kind === 's3') {
        return [NewPlace::s3('Off-site', 'https://s3.example.test', 'teme-backups', null, null, 'AKIATEST', 'secret-test-value'), 'secret-test-value'];
    }

    $pair = SftpKeyPair::generate();
    $place = NewPlace::sftp('Storage box', 'backup.example.com', 23, 'u123', '/backups/teme', $pair,
        SshHostKey::fromLine('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl'));

    // The body's fourth line holds the secret seed; the lines before it, the header and the public half.
    return [$place, explode("\n", $pair->privateKey())[4]];
}

it('never puts a key on a command line, setting backups up at a real folder (I-2400)', function (): void {
    $watched = WatchedProcesses::runForReal();
    $place = ReadyBackups::folderPlace('This server');
    $this->folders[] = (string) $place->location()['path'];

    $shown = ReadyBackups::setUp($place);
    ReadyBackups::confirm($shown);
    $machine = app(BackupKeys::class)->machineKey();
    $arguments = implode("\n", $watched->arguments());

    expect($watched->seen)->not->toBe([])
        ->and($arguments)->not->toContain($machine)
        ->and($arguments)->not->toContain($shown->key->value())
        ->and(array_values(array_filter(array_column($watched->seen, 'input'))))->toBe([$shown->key->value()."\n"]);

    foreach ($watched->seen as $process) {
        expect($process['environment']['RESTIC_PASSWORD'] ?? null)->toBe($machine);
    }
});

it('never puts S3’s secret or SFTP’s private key on a command line, or in a process’s environment but S3’s own', function (string $kind): void {
    $watched = WatchedProcesses::asRestic();
    [$place, $secret] = klPlace($kind);

    ReadyBackups::confirm(ReadyBackups::setUp($place));

    expect(implode("\n", $watched->arguments()))->not->toContain($secret);

    foreach ($watched->seen as $process) {
        $environment = array_filter($process['environment'], 'is_string');
        $holding = array_keys(array_filter($environment, fn (string $value): bool => str_contains($value, $secret)));

        expect($holding)->toBe($kind === 's3' ? ['AWS_SECRET_ACCESS_KEY'] : [])
            ->and((string) $process['input'])->not->toContain($secret);
    }
})->with(['s3', 'sftp']);

it('never writes a key to the log, even when restic repeats one back', function (): void {
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });
    FixedRecoveryKeys::bind();
    WatchedProcesses::asRestic()->failing('key add', 'Fatal: couldn’t save '.FixedRecoveryKeys::KEY.' with secret-test-value');

    expect(fn () => ReadyBackups::setUp(klPlace('s3')[0]))->toThrow(ValidationException::class);

    $log = implode("\n", $logged);

    expect($log)->toContain('Backups: adding a key at Off-site: Fatal: couldn’t save [hidden] with [hidden]')
        ->and($log)->not->toContain(FixedRecoveryKeys::KEY)
        ->and($log)->not->toContain('secret-test-value');
});

it('keeps no key in the clear in any column of any table', function (string $kind): void {
    WatchedProcesses::asRestic();
    [$place, $secret] = klPlace($kind);

    $shown = ReadyBackups::setUp($place);
    ReadyBackups::confirm($shown);

    expect(klInTheClear([$shown->key->value(), $shown->key->group(1).$shown->key->group(2), app(BackupKeys::class)->machineKey(), $secret]))->toBe([]);
})->with(['s3', 'sftp']);

it('leaves every key out of a failure’s stack trace, even where PHP keeps arguments', function (): void {
    $before = (string) ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    FixedRecoveryKeys::bind();
    WatchedProcesses::asRestic()->failing('key add');
    $failure = null;

    try {
        ReadyBackups::setUp(NewPlace::folder('This server', TemporaryRepository::freshFolder()));
    } catch (ValidationException $caught) {
        $failure = $caught;
    } finally {
        ini_set('zend.exception_ignore_args', $before);
    }

    $traces = '';

    for ($each = $failure; $each !== null; $each = $each->getPrevious()) {
        $traces .= $each->getTraceAsString();
    }

    expect($failure)->not->toBeNull()
        ->and($traces)->toContain('BackupKeys->add(')
        ->and($traces)->toContain('Object(SensitiveParameterValue)')
        ->and($traces)->not->toContain(substr(FixedRecoveryKeys::KEY, 0, 9));
});

it('never puts a key on a command line, in a process’s environment but restic’s own, or in the log, adding places and keys', function (): void {
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });
    $ready = ReadyBackups::atFolder();
    $second = ReadyBackups::folderPlace('Second place');
    $this->folders = [$ready->folder, (string) $second->location()['path']];
    $run = fn (object $operation, object $input): mixed => scElevated(fn (): mixed => $operation->execute($input));
    $watched = WatchedProcesses::runForReal();

    $run(app(AddBackupDestination::class), new AddBackupDestinationInput($second, $ready->recoveryKey));
    $added = $run(app(AddRecoveryKey::class), new AddRecoveryKeyInput('Held by Ada'));
    ReadyBackups::confirm($added);
    $replaced = $run(app(ReplaceRecoveryKey::class), new ReplaceRecoveryKeyInput('Recovery key'));
    ReadyBackups::confirm($replaced);
    $run(app(CheckRecoveryKey::class), new CheckRecoveryKeyInput($added->key));

    $machine = app(BackupKeys::class)->machineKey();
    $keys = [$machine, $ready->recoveryKey->value(), $added->key->value(), $replaced->key->value()];
    $arguments = implode("\n", $watched->arguments());

    foreach ($keys as $key) {
        expect($arguments)->not->toContain($key)
            ->and(implode("\n", $logged))->not->toContain($key);
    }

    foreach ($watched->seen as $process) {
        $holding = array_keys(array_filter($process['environment'], fn (string|false $value): bool => is_string($value) && in_array($value, $keys, true)));

        expect(array_diff($holding, ['RESTIC_PASSWORD', 'RESTIC_FROM_PASSWORD']))->toBe([])
            ->and($process['input'] === null || in_array(trim($process['input']), $keys, true))->toBeTrue();
    }

    expect(klInTheClear($keys))->toBe([]);
});

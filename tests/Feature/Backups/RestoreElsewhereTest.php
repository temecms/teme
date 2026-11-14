<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Backups\Restore\RestoreRefused;
use App\Domain\Backups\Restore\RestoreRequest;
use App\Domain\Backups\Restore\RestoreSite;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\NewServer;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScratchSite;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->key = 'base64:'.base64_encode(str_repeat('k', 32));
    NewServer::useAppKey($this->key);
    [$this->old, $this->new, $this->backups, $this->files] = [null, null, null, []];
    // The old server, backed up: its recovery key.
    $this->oldServer = function (): RecoveryKey {
        $this->old = ScratchSite::open();
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
        app()->forgetInstance(MaintenanceModeManager::class);
        $this->backups = RunnableBackups::atFolders(1);
        User::factory()->create(['name' => 'On the old server']);
        $key = NewServer::recoveryKey($this->backups);
        expect(app(BackupSite::class)->run(BackupTrigger::Command)->succeeded)->toBeTrue();

        return $key;
    };
});

afterEach(function (): void {
    $this->backups?->delete();
    $this->new?->close();
    $this->old?->close();

    foreach ($this->files as $file) {
        @unlink($file);
    }
});

it('restores onto a database that knows no place, from a folder named with the recovery key typed', function (): void {
    $recovery = ($this->oldServer)();
    $place = $this->backups->places[0];
    $this->new = NewServer::open($this->key);
    $typed = strtolower(str_replace('-', ' ', $recovery->value()));

    expect(BackupDestination::query()->count())->toBe(0);

    $result = app(RestoreSite::class)->run(new RestoreRequest(from: NewServer::folder($this->backups), key: RestoreRequest::keyFrom($typed)));

    expect($result->succeeded)->toBeTrue($result->sentence)
        ->and($result->safetyRunId)->toBeNull()
        ->and($result->sentence)->toContain(' from the place you named: ')
        ->and(DB::table('users')->pluck('name')->all())->toBe(['On the old server'])
        ->and(BackupDestination::query()->sole()->id)->toBe($place->id)
        ->and(app(BackupPlaces::class)->isPaused())->toBeTrue()
        ->and(BackupRun::query()->where('kind', BackupRunKind::Restore->value)->sole()->destination_id)->toBe($place->id)
        ->and(app(BackupKeys::class)->machineKey())->toBe(TemporaryRepository::KEY);
});

it('reads a recovery key in any case and spacing, or the machine key, and refuses anything else', function (): void {
    $recovery = RecoveryKey::generate();
    $machine = BackupKeys::newMachineKey();

    expect(RestoreRequest::keyFrom(' '.strtolower(str_replace('-', '  ', $recovery->value())).' '))->toBe($recovery->value())
        ->and(RestoreRequest::keyFrom(strtoupper($machine)))->toBe($machine)
        ->and(fn () => RestoreRequest::keyFrom('not a key'))->toThrow(ValidationException::class, 'That isn’t a recovery key or a machine key.');
});

it('hides the key it carries, and refuses to be stored or to carry a key without its place', function (): void {
    $request = new RestoreRequest(from: NewPlace::folder('the place you named', '/srv/backups'), key: BackupKeys::newMachineKey());

    expect(print_r($request, true))->not->toContain((string) $request->key())
        ->and(fn () => serialize($request))->toThrow(LogicException::class, 'A restore is never queued or stored: its key would go with it.')
        ->and(fn () => new RestoreRequest(key: 'x'))->toThrow(LogicException::class, 'A restore’s key comes with the place it opens.');
});

it('refuses a wrong key, or a place named with problems, before anything is written', function (): void {
    ($this->oldServer)();
    $this->new = NewServer::open($this->key);
    $refusal = function (RestoreRequest $request): array {
        try {
            app(RestoreSite::class)->run($request);
        } catch (RestoreRefused $refused) {
            return $refused->problems;
        }

        return [];
    };

    expect($refusal(new RestoreRequest(from: NewServer::folder($this->backups), key: RecoveryKey::generate()->value())))
        ->toBe(['The key didn’t open the backups at the place you named.'])
        ->and($refusal(new RestoreRequest(from: NewPlace::s3('the place you named', 'http://s3.example.com', 'teme-backups', null, null, 'AKIA', 'secret'), key: BackupKeys::newMachineKey())))
        ->toBe(['Give the storage’s address, starting https://, such as https://s3.eu-central-1.amazonaws.com.'])
        ->and(DB::table('users')->count())->toBe(0)
        ->and(app()->maintenanceMode()->active())->toBeFalse();
});

it('lists a folder’s backups with a key from a file, and refuses an SFTP place with no one to confirm its host key', function (): void {
    $recovery = ($this->oldServer)();
    $this->new = NewServer::open($this->key);
    $this->files[] = $file = TemporaryRepository::freshFolder().'.key';
    file_put_contents($file, $recovery->value()."\n");
    $runId = $this->backups->runsAt(0)[0]->runId;

    $this->artisan('teme:backup:list', ['--kind' => 'folder', '--path' => $this->backups->repositories[0]->folder, '--key-file' => $file])
        ->expectsOutput('Backups at the place you named, newest first:')
        ->expectsOutputToContain($runId)
        ->assertExitCode(0);

    $this->artisan('teme:backup:restore', ['--kind' => 'sftp', '--host' => '127.0.0.1', '--user' => 'teme', '--folder' => '/srv/teme', '--key-file' => $file, '--force' => true])
        ->expectsOutput('Name the server’s host key with --host-key when there’s no one to confirm its fingerprint.')
        ->assertExitCode(1);
});

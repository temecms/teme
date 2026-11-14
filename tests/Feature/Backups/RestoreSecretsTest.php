<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Restore\BackupAppKey;
use App\Domain\Backups\Restore\RestoreRequest;
use App\Domain\Backups\Restore\RestoreSite;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\NewServer;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScratchSite;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->oldKey = 'base64:'.base64_encode(str_repeat('o', 32));
    $this->newKey = 'base64:'.base64_encode(str_repeat('n', 32));
    [$this->old, $this->new, $this->backups] = [null, null, null];
});

afterEach(function (): void {
    $this->backups?->delete();
    $this->new?->close();
    $this->old?->close();
});

it('restores a backup made under another APP_KEY, its secrets re-encrypted for this server, and reports what no key opens', function (): void {
    NewServer::useAppKey($this->oldKey);
    $this->old = ScratchSite::open();
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
    app()->forgetInstance(MaintenanceModeManager::class);
    $this->backups = RunnableBackups::atFolders(1);
    // Fortify's own form: encrypt(), the value serialized inside (I-119).
    $person = User::factory()->create();
    DB::table('users')->where('id', $person->id)->update([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        // Under a key the backup doesn't carry: no key opens it.
        'two_factor_recovery_codes' => (new Encrypter(str_repeat('z', 32), 'AES-256-CBC'))->encrypt(json_encode(['aaaa-bbbb'])),
    ]);
    $lost = (string) DB::table('users')->where('id', $person->id)->value('two_factor_recovery_codes');
    $recovery = NewServer::recoveryKey($this->backups);
    expect(app(BackupSite::class)->run(BackupTrigger::Command)->succeeded)->toBeTrue();

    $this->new = NewServer::open($this->newKey);
    $steps = [];
    $result = app(RestoreSite::class)->run(new RestoreRequest(from: NewServer::folder($this->backups), key: $recovery->value()), function (string $step) use (&$steps): void {
        $steps[] = $step;
    });

    expect($result->succeeded)->toBeTrue($result->sentence)
        ->and($steps)->toContain('Re-encrypting the site’s secrets for this server’s APP_KEY.')
        ->and(app(BackupKeys::class)->machineKey())->toBe(TemporaryRepository::KEY)
        ->and(decrypt((string) DB::table('users')->where('id', $person->id)->value('two_factor_secret')))->toBe('JBSWY3DPEHPK3PXP')
        ->and((string) DB::table('users')->where('id', $person->id)->value('two_factor_recovery_codes'))->toBe($lost)
        ->and(BackupRun::query()->where('kind', BackupRunKind::Restore->value)->sole()->figures['secrets_unreadable'])->toBeGreaterThanOrEqual(1)
        ->and(count($result->notes))->toBeGreaterThanOrEqual(1);
});

it('hands 1.12b the backup’s key and its previous keys as app-key.json holds them', function (): void {
    $old = 'base64:'.base64_encode(str_repeat('p', 32));
    $key = BackupAppKey::fromArray(['key' => $this->newKey, 'previous_keys' => [$old]]);

    expect(json_decode($key->json(), true))->toBe(['key' => $this->newKey, 'previous_keys' => [$old]]);
});

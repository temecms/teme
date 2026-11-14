<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Settings\SecretStore;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\Fixtures\Security\OldKey;
use Tests\Fixtures\Security\Reencrypt;
use Tests\Fixtures\Security\SealedSecrets;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->old = OldKey::make();
});

it('lets a person finish signing in with a code from their secret, once re-encrypted', function (): void {
    $google = new Google2FA;
    $secret = $google->generateSecretKey();
    $person = User::factory()->create();
    SealedSecrets::twoFactor($person, $this->old->encrypter, $secret, ['ABCDEFGHIJ-KLMNOPQRST']);

    expect(fn () => $person->fresh()->twoFactorQrCodeUrl())->toThrow(DecryptException::class);

    Reencrypt::asOperator($this->old->keys());

    $this->post(route('login'), ['email' => $person->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['code' => $google->getCurrentOtp($secret)]);

    $this->assertAuthenticatedAs($person);
});

it('lets a person sign in with a recovery code, once re-encrypted, and keeps the codes under this server’s key', function (): void {
    $person = User::factory()->create();
    SealedSecrets::twoFactor($person, $this->old->encrypter, (new Google2FA)->generateSecretKey(), ['ABCDEFGHIJ-KLMNOPQRST', 'UVWXYZABCD-EFGHIJKLMN']);

    expect(fn () => $person->fresh()->recoveryCodes())->toThrow(DecryptException::class);

    Reencrypt::asOperator($this->old->keys());

    $this->post(route('login'), ['email' => $person->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'ABCDEFGHIJ-KLMNOPQRST']);

    $this->assertAuthenticatedAs($person);

    // Fortify replaced the code it used, writing under this server's key.
    $stored = (string) DB::table('users')->where('id', $person->id)->value('two_factor_recovery_codes');

    expect($person->fresh()->recoveryCodes())->toHaveCount(2)
        ->toContain('UVWXYZABCD-EFGHIJKLMN')
        ->not->toContain('ABCDEFGHIJ-KLMNOPQRST')
        ->and(SealedSecrets::underServerKeyAlone($stored))->not->toBeNull();
});

it('lets SecretStore read a re-encrypted secret, and its group’s other settings change again', function (): void {
    SealedSecrets::settings(BackupSettings::GROUP, ['machine_key' => str_repeat('c', 64)], $this->old->encrypter);
    $read = fn (): ?string => app(SecretStore::class)->secret(BackupSettings::GROUP, 'machine_key');
    $write = fn (): int => app(SettingsWriter::class)->write(new SettingsWrite(BackupSettings::GROUP, ['hour' => '03:00']));

    // Before: the secret doesn't read, and no change to the group saves.
    expect($read)->toThrow(DecryptException::class)
        ->and($write)->toThrow(DecryptException::class);

    Reencrypt::asOperator($this->old->keys());

    expect($read())->toBe(str_repeat('c', 64))
        ->and($write)->not->toThrow(Throwable::class)
        ->and($read())->toBe(str_repeat('c', 64));
});

it('lets a backup place’s credentials open through its model, once re-encrypted', function (): void {
    $place = SealedSecrets::place($this->old->encrypter, ['access_key_id' => 'AKIAOLD', 'secret_access_key' => 'old-secret']);
    $credentials = fn (): mixed => BackupDestination::query()->findOrFail($place->id)->credentials;

    expect($credentials)->toThrow(DecryptException::class);

    Reencrypt::asOperator($this->old->keys());

    expect($credentials())->toBe(['access_key_id' => 'AKIAOLD', 'secret_access_key' => 'old-secret']);
});

it('lets a protected item’s password open through ReaderPasswords, once re-encrypted', function (): void {
    $this->seed(RoleSeeder::class);
    $item = cmItem(User::factory()->create()->assignRole('editor'), 'Harbour news');
    DB::table('contents')->where('id', $item->id)->update(['visibility' => 'protected', 'reader_password' => $this->old->encrypter->encryptString('Harbour 2024')]);

    expect(ReaderPasswords::open($item->fresh()))->toBeNull();

    Reencrypt::asOperator($this->old->keys());

    expect(ReaderPasswords::open($item->fresh()))->toBe('Harbour 2024');
});

it('lets an import source’s password open through its model, once re-encrypted', function (): void {
    importFake();
    $source = importSource();
    DB::table('import_sources')->where('id', $source->id)->update(['credential' => $this->old->encrypter->encryptString('abcd efgh ijkl mnop')]);

    expect(fn () => $source->fresh()->credential)->toThrow(DecryptException::class);

    Reencrypt::asOperator($this->old->keys());

    expect($source->fresh()->credential)->toBe('abcd efgh ijkl mnop');
});

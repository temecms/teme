<?php

declare(strict_types=1);

use App\Console\Commands\ReencryptStoredSecrets;
use App\Domain\Backups\BackupSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Security\OldKey;
use Tests\Fixtures\Security\SealedSecrets;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    SealedSecrets::serverPreviousKeys([]);

    $this->old = OldKey::make();
    $this->person = User::factory()->create();
    SealedSecrets::twoFactor($this->person, $this->old->encrypter, 'JBSWY3DPEHPK3PXP', ['AAAAAAAAAA-BBBBBBBBBB']);
    SealedSecrets::settings(BackupSettings::GROUP, ['machine_key' => str_repeat('d', 64)], $this->old->encrypter);

    $this->files = [];
    $this->file = function (string $contents): string {
        $path = (string) tempnam(sys_get_temp_dir(), 'teme-key-');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    };

    $this->allOpen = function (): bool {
        foreach (SealedSecrets::stored() as $stored) {
            if (SealedSecrets::underServerKeyAlone($stored) === null) {
                return false;
            }
        }

        return true;
    };
});

afterEach(function (): void {
    foreach ($this->files as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('asks for the old key, re-encrypts, and says so', function (): void {
    $this->artisan('teme:secrets:reencrypt')
        ->expectsQuestion('The old APP_KEY, as the old server had it', $this->old->typed)
        ->expectsOutputToContain('Settings’ secrets: 1 re-encrypted')
        ->expectsOutputToContain('Two-factor secrets: 1 re-encrypted')
        ->expectsOutputToContain('Two-factor recovery codes: 1 re-encrypted')
        ->expectsOutputToContain('Backup places’ credentials: none kept')
        ->expectsOutputToContain('Every encrypted value now opens with this server’s key.')
        ->doesntExpectOutputToContain('APP_PREVIOUS_KEYS now')
        ->doesntExpectOutputToContain(substr($this->old->typed, 7))
        ->assertExitCode(0);

    expect(($this->allOpen)())->toBeTrue();
});

it('reads the old key from a backup’s app-key.json', function (): void {
    $path = ($this->file)((string) json_encode(['key' => $this->old->typed, 'previous_keys' => []]));

    $this->artisan('teme:secrets:reencrypt', ['--key-file' => $path])
        ->expectsOutputToContain('Every encrypted value now opens with this server’s key.')
        ->assertExitCode(0);

    expect(($this->allOpen)())->toBeTrue();
});

it('reads the old key from a copy of the old server’s .env file', function (): void {
    $path = ($this->file)("APP_NAME=Teme\nAPP_ENV=production\nAPP_KEY={$this->old->typed}\nAPP_PREVIOUS_KEYS=\n\nDB_CONNECTION=pgsql\n");

    $this->artisan('teme:secrets:reencrypt', ['--key-file' => $path])
        ->expectsOutputToContain('Every encrypted value now opens with this server’s key.')
        ->assertExitCode(0);

    expect(($this->allOpen)())->toBeTrue();
});

it('refuses a file it can’t read, or one that holds no key, writing nothing and showing nothing of it', function (): void {
    $before = SealedSecrets::stored();
    $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-no-such-key-file';
    $empty = ($this->file)("# nothing here\nAPP_NAME=Teme\n");
    $broken = ($this->file)('base64:hunter2!!');

    $this->artisan('teme:secrets:reencrypt', ['--key-file' => $missing])
        ->expectsOutputToContain("Teme can’t read {$missing}. Nothing was changed.")
        ->assertExitCode(1);

    $this->artisan('teme:secrets:reencrypt', ['--key-file' => $empty])
        ->expectsOutputToContain('No application key was given. Nothing was changed.')
        ->assertExitCode(1);

    $this->artisan('teme:secrets:reencrypt', ['--key-file' => $broken])
        ->expectsOutputToContain('That isn’t an application key. Give it as the old server’s environment had it: base64: and the characters after it. Nothing was changed.')
        ->doesntExpectOutputToContain('hunter2')
        ->assertExitCode(1);

    expect(SealedSecrets::stored())->toBe($before);
});

it('uses only this server’s APP_PREVIOUS_KEYS when nothing is typed, and refuses when there are none', function (): void {
    $this->artisan('teme:secrets:reencrypt')
        ->expectsQuestion('The old APP_KEY, as the old server had it', '')
        ->expectsOutputToContain('Give the old key: type it at the prompt, or name a file with --key-file. Nothing was changed.')
        ->assertExitCode(1);

    SealedSecrets::serverPreviousKeys([$this->old->typed]);

    $this->artisan('teme:secrets:reencrypt')
        ->expectsQuestion('The old APP_KEY, as the old server had it (press Enter to use only this server’s APP_PREVIOUS_KEYS)', '')
        ->expectsOutputToContain('Every encrypted value now opens with this server’s key.')
        ->expectsOutputToContain('Nothing Teme keeps needs APP_PREVIOUS_KEYS now: remove the old key from the server’s environment, and restart.')
        ->assertExitCode(0);

    expect(($this->allOpen)())->toBeTrue();
});

it('names each value no key opens, and fails', function (): void {
    $stranger = User::factory()->create();
    SealedSecrets::twoFactor($stranger, OldKey::make()->encrypter, 'MFRGGZDFMZTWQ2LK', ['GGGGGGGGGG-HHHHHHHHHH']);

    $this->artisan('teme:secrets:reencrypt')
        ->expectsQuestion('The old APP_KEY, as the old server had it', $this->old->typed)
        ->expectsOutputToContain("Two-factor secrets: 1 re-encrypted, 1 opens with no key given (person {$stranger->id})")
        ->expectsOutputToContain('2 values open with no key given and were left as they were. Run this again with the key they were made with; everything else is done.')
        ->expectsOutputToContain('A person listed under two-factor can’t finish signing in with it until their values open.')
        ->assertExitCode(1);
});

it('takes no key as an argument', function (): void {
    $definition = (new ReencryptStoredSecrets)->getDefinition();

    expect($definition->getArguments())->toBe([])
        ->and(array_keys($definition->getOptions()))->toBe(['key-file']);
});

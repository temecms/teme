<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupSettings;
use App\Domain\Security\EncryptedColumn;
use App\Domain\Security\EncryptedForm;
use App\Domain\Security\EncryptedValues;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Security\EncryptedCasts;
use Tests\Fixtures\Security\EncryptingCalls;
use Tests\Fixtures\Security\UnlistedSecret;

uses(RefreshDatabase::class);

it('lists every value core encrypts, each in its form', function (): void {
    $listed = [];

    foreach (app(EncryptedValues::class)->all() as $column) {
        $listed[$column->name()] = [$column->form, $column->key, $column->label, $column->row];
    }

    expect($listed)->toMatchArray([
        'settings.secrets' => [EncryptedForm::Json, 'group_key', 'Settings’ secrets', 'settings group'],
        'users.two_factor_secret' => [EncryptedForm::Serialized, 'id', 'Two-factor secrets', 'person'],
        'users.two_factor_recovery_codes' => [EncryptedForm::Serialized, 'id', 'Two-factor recovery codes', 'person'],
        'backup_destinations.credentials' => [EncryptedForm::Json, 'id', 'Backup places’ credentials', 'backup place'],
    ]);
});

it('names only text columns that exist, each paged by a unique key', function (): void {
    foreach (app(EncryptedValues::class)->all() as $column) {
        $unique = collect(Schema::getIndexes($column->table))
            ->contains(fn (array $index): bool => $index['columns'] === [$column->key] && ($index['primary'] || $index['unique']));

        expect(Schema::hasColumns($column->table, [$column->key, $column->column]))->toBeTrue($column->name())
            ->and(Schema::getColumnType($column->table, $column->column))->toBe('text', $column->name())
            ->and($unique)->toBeTrue($column->name());
    }
});

it('has every encrypted cast on a model in app/ registered, in the form its cast writes', function (): void {
    $models = EncryptedCasts::appModels();

    // Not a scan that finds nothing: the two casts core has today are found.
    expect(EncryptedCasts::found($models))->toMatchArray([
        'settings.secrets' => EncryptedForm::Json,
        'backup_destinations.credentials' => EncryptedForm::Json,
    ])
        ->and(EncryptedCasts::unlisted($models, app(EncryptedValues::class)->all()))->toBe([]);
});

it('finds an encrypted cast nobody registered', function (): void {
    expect(EncryptedCasts::unlisted([UnlistedSecret::class], app(EncryptedValues::class)->all()))
        ->toBe(['unlisted_secrets.token (string)', 'unlisted_secrets.settings (json)']);
});

it('accounts for every call that encrypts or adds casts, wherever Teme’s own code runs', function (): void {
    // A file that encrypts into a table names the registered column it
    // writes; one that keeps what it encrypts in no table says why, in
    // words that begin "keeps nothing". Accounted per file: a change to an
    // accounted file is checked by hand.
    $accounted = [
        'app/Domain/Contents/ReaderPasswords.php' => 'contents.reader_password',
        'app/Domain/Security/Operations/ReencryptSecrets.php' => 'keeps nothing new: it only writes the registered columns’ own values again',
        'app/Http/Admin/Tools/Backups/SftpSteps.php' => 'keeps nothing: the session only, never a table',
    ];

    $registered = array_map(fn (EncryptedColumn $column): string => $column->name(), app(EncryptedValues::class)->all());
    $calls = [];

    foreach (['app', 'database/migrations', 'database/seeders', 'routes', 'bootstrap', 'config', 'packages'] as $folder) {
        $calls = [...$calls, ...EncryptingCalls::in(base_path($folder), base_path())];
    }

    $files = array_values(array_unique(array_map(fn (string $call): string => explode(':', $call)[0], $calls)));

    expect(array_values(array_diff($files, array_keys($accounted))))->toBe([])
        ->and(array_filter($accounted, fn (string $what): bool => ! str_starts_with($what, 'keeps nothing') && ! in_array($what, $registered, true)))->toBe([]);
});

it('finds a call that encrypts or adds casts, and never one in a comment, a string or a method’s name', function (): void {
    expect(EncryptingCalls::in(base_path('tests/Fixtures/Security/Scanned'), base_path()))->toBe([
        'tests/Fixtures/Security/Scanned/SealsSomething.php:21',
        'tests/Fixtures/Security/Scanned/SealsSomething.php:26',
        'tests/Fixtures/Security/Scanned/SealsSomething.php:36',
    ]);
});

it('finds Fortify still writing its two values serialized inside, as the registry says', function (): void {
    $person = User::factory()->create();

    app(EnableTwoFactorAuthentication::class)($person);
    $enabled = DB::table('users')->where('id', $person->id)->first(['two_factor_secret', 'two_factor_recovery_codes']);

    app(GenerateNewRecoveryCodes::class)($person->fresh());
    $regenerated = DB::table('users')->where('id', $person->id)->value('two_factor_recovery_codes');

    expect(Crypt::decrypt($enabled->two_factor_secret, false))->toMatch('/^s:\d+:"[A-Z2-7]+";$/')
        ->and(Crypt::decrypt($enabled->two_factor_recovery_codes, false))->toMatch('/^s:\d+:"\[.+\]";$/s')
        ->and(Crypt::decrypt($regenerated, false))->toMatch('/^s:\d+:"\[.+\]";$/s');
});

it('finds the casts writing JSON inside, as the registry says', function (): void {
    $place = BackupRecords::destination(['kind' => BackupDestinationKind::S3]);
    app(SettingsWriter::class)->write(new SettingsWrite(BackupSettings::GROUP, [], secrets: ['machine_key' => str_repeat('a', 64)]));

    $credentials = DB::table('backup_destinations')->where('id', $place->id)->value('credentials');
    $secrets = DB::table('settings')->where('group_key', BackupSettings::GROUP)->value('secrets');

    expect(json_decode(Crypt::decryptString($credentials), true))->toBe(['access_key_id' => 'AKIATEST', 'secret_access_key' => 'test-secret'])
        ->and(json_decode(Crypt::decryptString($secrets), true))->toBe(['machine_key' => str_repeat('a', 64)]);
});

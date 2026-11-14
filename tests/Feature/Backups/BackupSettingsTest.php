<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSettings;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\Queries\FindSettings;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Domain\Settings\SecretStore;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Settings\SettingType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('registers the backups group: nightly at 02:00, keeping 7 daily, 4 weekly and 12 monthly backups (I-361)', function (): void {
    $group = app(SettingsRegistry::class)->find('backups');
    $settings = app(SettingsReader::class);

    expect($group)->not->toBeNull()
        ->and($group->ability)->toBe('backups.manage')
        ->and($group->sensitive)->toBeTrue()
        ->and($group->screen)->toBe(BackupSettings::SCREEN)
        ->and(array_map(fn (Setting $setting): string => $setting->key, $group->settings))
        ->toBe(['hour', 'keep_daily', 'keep_weekly', 'keep_monthly', 'machine_key'])
        ->and($settings->string('backups', 'hour'))->toBe('02:00')
        ->and($settings->int('backups', 'keep_daily'))->toBe(7)
        ->and($settings->int('backups', 'keep_weekly'))->toBe(4)
        ->and($settings->int('backups', 'keep_monthly'))->toBe(12)
        ->and([$group->setting('keep_daily')->min, $group->setting('keep_daily')->max])->toBe([1, 90])
        ->and([$group->setting('keep_weekly')->min, $group->setting('keep_weekly')->max])->toBe([0, 52])
        ->and([$group->setting('keep_monthly')->min, $group->setting('keep_monthly')->max])->toBe([0, 120])
        ->and($group->setting('machine_key')->type)->toBe(SettingType::Secret)
        ->and($group->setting('machine_key')->env)->toBe('TEME_BACKUP_MACHINE_KEY');
});

it('offers the 24 hours as text from 00:00 to 23:00, never as numbers', function (): void {
    $options = app(SettingsRegistry::class)->find('backups')->setting('hour')->options;

    expect($options)->toHaveCount(24)
        ->and(array_key_first($options))->toBe('00:00')
        ->and(array_key_last($options))->toBe('23:00')
        ->and(array_filter(array_keys($options), 'is_string'))->toHaveCount(24)
        ->and($options['13:00'])->toBe('13:00');
});

it('is never a Settings page, and Find a setting never lists it', function (): void {
    // Both words are in "Daily backups kept", so a listed group would be found.
    $found = app(FindSettings::class)->run('backups kept', $this->admin);

    expect(array_column(app(SettingsNavigation::class)->forUser($this->admin->id), 'key'))->not->toContain('backups')
        ->and(array_column($found['screens'], 'key'))->not->toContain('backups')
        ->and(array_filter(array_column($found['settings'], 'key'), fn (string $key): bool => str_starts_with($key, 'backups.')))->toBe([]);
});

it('sends an administrator who opens it under Settings to its own screen, and refuses everyone else', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/backups')->assertRedirect(route(BackupSettings::SCREEN));
    $this->actingAs($this->editor)->get('/admin/settings/backups')->assertForbidden();
});

it('refuses a change through the generic settings form, and writes nothing', function (): void {
    $change = fn (User $actor): mixed => scElevated(fn (): mixed => app(UpdateSettings::class)->execute(
        new UpdateSettingsInput('backups', ['hour' => '03:00'], [], [], 0, (string) $actor->id),
    ));

    expect(fn () => $change($this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $change($this->editor))->toThrow(AuthorizationException::class)
        ->and(DB::table('settings')->where('group_key', 'backups')->exists())->toBeFalse()
        ->and(app(SettingsReader::class)->string('backups', 'hour'))->toBe('02:00');
});

it('reads the machine key from the server\'s environment before the database, and says where it came from', function (): void {
    $store = app(SecretStore::class);
    $variable = 'TEME_BACKUP_MACHINE_KEY';
    // phpunit.xml sets it empty, so a developer's .env never reaches a test; put back whatever was there.
    [$fromGetenv, $fromEnv, $fromServer] = [getenv($variable), $_ENV[$variable] ?? null, $_SERVER[$variable] ?? null];

    expect($store->secret('backups', 'machine_key'))->toBeNull();

    putenv("{$variable}=from-the-server");
    $_ENV[$variable] = $_SERVER[$variable] = 'from-the-server';

    try {
        expect($store->secret('backups', 'machine_key'))->toBe('from-the-server')
            ->and($store->status('backups', 'machine_key'))->toBe(['isSet' => true, 'source' => 'environment']);
    } finally {
        $fromGetenv === false ? putenv($variable) : putenv("{$variable}={$fromGetenv}");

        if ($fromEnv === null) {
            unset($_ENV[$variable]);
        } else {
            $_ENV[$variable] = $fromEnv;
        }

        if ($fromServer === null) {
            unset($_SERVER[$variable]);
        } else {
            $_SERVER[$variable] = $fromServer;
        }
    }
});

<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Operations\ChangeBackupSchedule;
use App\Domain\Backups\Operations\Input\ChangeBackupScheduleInput;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Backups\ScreenRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    ScreenRecords::settings(['general' => ['timezone' => 'UTC'], BackupSettings::GROUP => ['hour' => '02:00']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', 'UTC'));
    $this->place = ScreenRecords::place();
    ScreenRecords::backup($this->place, '2026-10-20 02:05');
    $this->send = fn (array $values, int $version = 1) => $this->actingAs($this->admin)
        ->withSession(scConfirmed())
        ->put('/admin/tools/backups/schedule', ['values' => $values, 'expected_version' => $version]);
});

it('saves the hour and what’s kept, logs it, and says when the next backup starts', function (): void {
    // Tonight's backup began at 02:05, after 01:00: the next is tomorrow's.
    ($this->send)(['hour' => '01:00', 'keep_daily' => '14', 'keep_weekly' => '0', 'keep_monthly' => '24'])
        ->assertRedirect(route('tools.backups.index'))
        ->assertInertiaFlash('toast.message', 'Saved. The next backup starts tomorrow at 01:00.');

    $settings = app(SettingsReader::class);

    expect($settings->string(BackupSettings::GROUP, 'hour'))->toBe('01:00')
        ->and($settings->int(BackupSettings::GROUP, 'keep_daily'))->toBe(14)
        ->and($settings->int(BackupSettings::GROUP, 'keep_weekly'))->toBe(0)
        ->and($settings->int(BackupSettings::GROUP, 'keep_monthly'))->toBe(24)
        ->and(DB::table('security_events')->where('type', 'settings.security_changed')->count())->toBe(1);
});

it('refuses a number outside the setting’s range, under its control, writing nothing', function (): void {
    ($this->send)(['hour' => '02:00', 'keep_daily' => '0', 'keep_weekly' => '4', 'keep_monthly' => '12'])
        ->assertSessionHasErrors('values.keep_daily');

    expect(SettingsGroupRecord::query()->whereKey(BackupSettings::GROUP)->value('version'))->toBe(1);
});

it('refuses an hour that isn’t one of the twenty-four, and the machine key', function (): void {
    ($this->send)(['hour' => '02:30'])->assertSessionHasErrors('values.hour');
    ($this->send)(['machine_key' => str_repeat('a', 64)])->assertSessionHasErrors('values.machine_key');

    expect(SettingsGroupRecord::query()->whereKey(BackupSettings::GROUP)->value('version'))->toBe(1);
});

it('refuses a form opened on an older version', function (): void {
    ($this->send)(['hour' => '05:00'], version: 0)
        ->assertSessionHasErrors(['expected_version' => 'These settings were changed by someone else while you had them open. Reload the page and try again.']);
});

it('asks for the password again before changing anything', function (): void {
    $this->actingAs($this->admin)
        ->put('/admin/tools/backups/schedule', ['values' => ['hour' => '05:00'], 'expected_version' => 1])
        ->assertRedirect(route('password.confirm'));

    expect(app(SettingsReader::class)->string(BackupSettings::GROUP, 'hour'))->toBe('02:00');
});

it('writes nothing when authorization fails', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => scElevated(fn () => app(ChangeBackupSchedule::class)->execute(new ChangeBackupScheduleInput(['hour' => '05:00'], 1, (string) $editor->id))))
        ->toThrow(AuthorizationException::class, 'Only an administrator manages backups.');

    expect(SettingsGroupRecord::query()->whereKey(BackupSettings::GROUP)->value('version'))->toBe(1)
        ->and(DB::table('security_events')->where('type', 'settings.security_changed')->count())->toBe(0);
});

it('refuses the route to everyone but an administrator', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('editor'))
        ->withSession(scConfirmed())
        ->put('/admin/tools/backups/schedule', ['values' => ['hour' => '05:00'], 'expected_version' => 1])
        ->assertForbidden();
});

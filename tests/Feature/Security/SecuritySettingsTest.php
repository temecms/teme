<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

it('registers the security group as sensitive, for administrators only', function (): void {
    $this->seed(RoleSeeder::class);
    $group = app(SettingsRegistry::class)->find('security');

    expect($group)->not->toBeNull()
        ->and($group->sensitive)->toBeTrue()
        ->and($group->ability)->toBe('settings.security.manage')
        ->and(array_map(fn (Setting $setting): string => $setting->key, $group->settings))->toBe([
            'password_min_length',
            'password_check_breached',
            'login_attempts_per_account_ip',
            'login_attempts_per_ip',
            'login_untrusted_attempts_per_account',
            'session_idle_minutes',
            'session_absolute_hours',
            'session_remember_days',
            'elevation_minutes',
            'two_factor_required_roles',
        ])
        ->and(User::factory()->create()->assignRole('administrator')->can('settings.security.manage'))->toBeTrue()
        ->and(User::factory()->create()->assignRole('editor')->can('settings.security.manage'))->toBeFalse();
});

it('defaults to study G\'s values', function (): void {
    $settings = app(SettingsReader::class);

    expect($settings->int('security', 'password_min_length'))->toBe(15)
        ->and($settings->bool('security', 'password_check_breached'))->toBeTrue()
        ->and($settings->int('security', 'login_attempts_per_account_ip'))->toBe(5)
        ->and($settings->int('security', 'login_attempts_per_ip'))->toBe(50)
        ->and($settings->int('security', 'login_untrusted_attempts_per_account'))->toBe(10)
        ->and($settings->int('security', 'session_idle_minutes'))->toBe(120)
        ->and($settings->int('security', 'session_absolute_hours'))->toBe(24)
        ->and($settings->int('security', 'session_remember_days'))->toBe(14)
        ->and($settings->int('security', 'elevation_minutes'))->toBe(15);
});

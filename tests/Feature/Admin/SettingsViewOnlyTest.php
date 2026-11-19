<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->viewer = User::factory()->create()->assignRole(Role::findOrCreate('settings-viewer')->givePermissionTo('settings.view'));
});

it('shows a group read-only to someone who may see settings but not change them', function (): void {
    $this->actingAs($this->viewer)
        ->get('/admin/settings/general')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/group')
            ->where('readOnly.permission', 'Manage general settings')
            ->where('readOnly.holders', 'administrators'));
});

it('leaves the readOnly line out for someone who may change the group', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)
        ->get('/admin/settings/general')
        ->assertInertia(fn (Assert $page) => $page->where('readOnly', null));
});

it('lists the groups such a person may see in the Settings menu', function (): void {
    $nav = $this->actingAs($this->viewer)->get('/admin/settings/general')->inertiaProps()['settingsNav'];

    expect(array_column($nav, 'key'))->toContain('general', 'people');
});

it('saves nothing for someone who may only see the settings', function (): void {
    $this->actingAs($this->viewer)
        ->put('/admin/settings/general', ['values' => ['site_title' => 'Taken over'], 'expected_version' => 0])
        ->assertForbidden();

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('My Teme site');
});

it('still forbids a group to someone who may neither see nor change settings', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin/settings/general')->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Domain\Settings\Queries\SettingsNavigation;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('orders core\'s Settings pages by the menu\'s own order, not by when their parts registered them', function (): void {
    $keys = array_column(app(SettingsNavigation::class)->forUser($this->admin->id), 'key');

    // Security registers second and Content after Addresses (bootstrap/providers.php); the menu puts Writing, the content group, second, and People, the security group, after Media.
    expect(array_values(array_intersect($keys, ['general', 'writing', 'addresses', 'media', 'people'])))
        ->toBe(['general', 'writing', 'addresses', 'media', 'people']);
});

it('puts a page the order doesn\'t name after core\'s, and a plugin\'s group last, under From plugins', function (): void {
    $registry = app(SettingsRegistry::class);
    // The plugin's group registers first, so its place can't come from registering.
    $registry->group(new SettingsGroup(
        key: 'plugin.example',
        label: 'Example',
        description: 'A plugin’s group for this test.',
        section: 'example',
        ability: 'settings.general.manage',
        settings: [Setting::text('label', 'Label', 'A label for this test.', '0.1.0')],
        since: '0.1.0',
    ));
    $registry->group(new SettingsGroup(
        key: 'test_extra',
        label: 'Extra',
        description: 'A core group for this test.',
        section: 'test_extra',
        ability: 'settings.general.manage',
        settings: [Setting::text('label', 'Label', 'A label for this test.', '0.1.0')],
        since: '0.1.0',
    ));

    $menu = app(SettingsNavigation::class)->forUser($this->admin->id);

    expect(array_slice($menu, -2))->toBe([
        ['key' => 'test_extra', 'label' => 'Extra', 'href' => route('settings.show', ['group' => 'test_extra'], false), 'heading' => 'Site', 'plugin' => false],
        ['key' => 'plugin.example', 'label' => 'Example', 'href' => route('settings.show', ['group' => 'plugin.example'], false), 'heading' => 'From plugins', 'plugin' => true],
    ]);
});

it('lists only what the person may open, each with its address and heading', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $editor->givePermissionTo('settings.media.manage');

    expect(app(SettingsNavigation::class)->forUser($editor->id))->toBe([
        ['key' => 'media', 'label' => 'Media', 'href' => route('settings.show', ['group' => 'media'], false), 'heading' => 'Site', 'plugin' => false],
    ]);
});

it('frames every Settings screen with the Settings menu, and no screen draws its own', function (): void {
    expect(uiComponent('app.tsx'))->toContain("case name.startsWith('settings/'):")
        ->toContain('[AppLayout, SettingsLayout]');

    $screens = glob(resource_path('js/pages/settings/*.tsx')) ?: [];

    expect($screens)->not->toBeEmpty();

    foreach ($screens as $screen) {
        expect((string) file_get_contents($screen))->not->toContain('TabStrip')->not->toContain('settingsNav');
    }
});

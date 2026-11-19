<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

/**
 * Example sync's declared settings, as its teme-plugin.json gives them.
 *
 * @return list<array<string, mixed>>
 */
function exampleSettings(): array
{
    return [
        ['key' => 'token', 'type' => 'secret', 'label' => 'Token', 'description' => 'Lets Teme tell your workspace.'],
        ['key' => 'project', 'type' => 'text', 'label' => 'Project', 'description' => 'The workspace project to tell.', 'default' => 'blog', 'maxLength' => 60, 'required' => true, 'example' => 'my-blog'],
        ['key' => 'tries', 'type' => 'number', 'label' => 'Tries', 'description' => 'How often Teme tries again.', 'default' => 3, 'min' => 1, 'max' => 9, 'unit' => 'tries'],
        ['key' => 'mode', 'type' => 'select', 'label' => 'Mode', 'description' => 'What Teme tells the workspace.', 'options' => ['all' => 'Everything', 'posts' => 'Posts only'], 'default' => 'posts'],
    ];
}

it('builds a settings group named for the plugin, for plugins.manage holders', function (): void {
    $group = DeclaredSettings::group('example-sync', 'Example sync', 'Tells your keyword workspace what you publish.', '1.2.0', exampleSettings());

    expect($group->key)->toBe('plugin.example-sync')
        ->and($group->label)->toBe('Example sync')
        ->and($group->description)->toBe('Tells your keyword workspace what you publish.')
        ->and($group->section)->toBe('plugins')
        ->and($group->ability)->toBe('plugins.manage')
        ->and($group->since)->toBe('1.2.0')
        ->and($group->cacheTags)->toBe([])
        ->and(array_map(fn ($setting): array => [$setting->key, $setting->type, $setting->default, $setting->since], $group->settings))->toBe([
            ['token', SettingType::Secret, null, '1.2.0'],
            ['project', SettingType::Text, 'blog', '1.2.0'],
            ['tries', SettingType::Number, 3, '1.2.0'],
            ['mode', SettingType::Select, 'posts', '1.2.0'],
        ])
        ->and([$group->setting('project')?->maxLength, $group->setting('project')?->required, $group->setting('project')?->example])->toBe([60, true, 'my-blog'])
        ->and([$group->setting('tries')?->min, $group->setting('tries')?->max, $group->setting('tries')?->unit])->toBe([1, 9, 'tries'])
        ->and($group->setting('mode')?->options)->toBe(['all' => 'Everything', 'posts' => 'Posts only']);
});

it('makes a group with a secret sensitive, so changing it asks for the password', function (): void {
    $group = DeclaredSettings::group('example-sync', 'Example sync', 'Tells your keyword workspace what you publish.', '1.2.0', exampleSettings());
    $plain = DeclaredSettings::group('example-sync', 'Example sync', 'Tells your keyword workspace what you publish.', '1.2.0', array_slice(exampleSettings(), 1));

    expect($group->sensitive)->toBeTrue()->and($plain->sensitive)->toBeFalse();

    app(SettingsRegistry::class)->group($group, 'acme/example-sync');
    app()->instance(Elevation::class, new DeniedElevation);
    $admin = User::factory()->create()->assignRole('administrator');

    expect(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.example-sync', [], [], ['token' => 'secret-token'], 0, (string) $admin->id)))
        ->toThrow(ElevationRequiredException::class);
    $this->assertDatabaseMissing('settings', ['group_key' => 'plugin.example-sync']);
});

it('files the group under From plugins and draws it with the settings form', function (): void {
    app(SettingsRegistry::class)->group(DeclaredSettings::group('example-sync', 'Example sync', 'Tells your keyword workspace what you publish.', '1.2.0', exampleSettings()), 'acme/x');
    $admin = User::factory()->create()->assignRole('administrator');
    $editor = User::factory()->create()->assignRole('editor');

    $listed = collect(app(SettingsNavigation::class)->forUser($admin->id))->firstWhere('key', 'plugin.example-sync');

    expect($listed)->toBe(['key' => 'plugin.example-sync', 'label' => 'Example sync', 'href' => '/admin/settings/plugin.example-sync', 'heading' => 'From plugins', 'plugin' => true])
        ->and(array_column(app(SettingsNavigation::class)->forUser($editor->id), 'key'))->not->toContain('plugin.example-sync');

    $this->actingAs($admin)->get('/admin/settings/plugin.example-sync')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/group')->where('group.key', 'plugin.example-sync'));
    $this->actingAs($editor)->get('/admin/settings/plugin.example-sync')->assertForbidden();
});

it('refuses settings Teme can’t register, saying why', function (array $settings, string $words): void {
    expect(app(ContributionsValidator::class)->errors(['settings' => $settings], '1.2.0'))->toBe([$words]);
})->with([
    'a key with a capital' => [
        [['key' => 'Token', 'type' => 'text', 'label' => 'Token', 'description' => 'The token.']],
        'Its settings can’t be used: Invalid setting key [plugin.settings.Token].',
    ],
    'a key with a trailing newline' => [
        [['key' => "token\n", 'type' => 'text', 'label' => 'Token', 'description' => 'The token.']],
        "Its settings can’t be used: Invalid setting key [plugin.settings.token\n].",
    ],
    'two settings with one key' => [
        [['key' => 'token', 'type' => 'text', 'label' => 'Token', 'description' => 'The token.'], ['key' => 'token', 'type' => 'secret', 'label' => 'Token', 'description' => 'The token.']],
        'Its settings can’t be used: Setting [plugin.settings.token] is declared twice.',
    ],
    'a select without options' => [
        [['key' => 'mode', 'type' => 'select', 'label' => 'Mode', 'description' => 'The mode.']],
        'Its settings can’t be used: Select setting [mode] needs at least one option.',
    ],
    'an unknown type' => [
        [['key' => 'when', 'type' => 'date', 'label' => 'When', 'description' => 'The day.']],
        'Its settings can’t be used: Setting [plugin.settings.when] has an unknown type [date].',
    ],
]);

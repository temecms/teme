<?php

declare(strict_types=1);

use App\Domain\Navigation\ToolsHub;
use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');

    $this->start = function (string $folder = 'placard'): void {
        PluginFixtures::use($folder);
        $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
        app(PluginLoader::class)->load();
    };
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('shows settings as the last tab of a plugin’s area', function (): void {
    ($this->start)();

    $this->actingAs($this->admin)->get(route('plugins.area.settings', ['code' => 'placard']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plugins/settings')
            ->where('current.id', 'settings')
            ->where('tabs.'.(count(app(PluginAreas::class)->find('placard')->area->pages)).'.id', 'settings')
            ->where('tabs.'.(count(app(PluginAreas::class)->find('placard')->area->pages)).'.current', true)
            ->where('group.key', 'plugin.placard'));

    $this->actingAs($this->admin)->get(route('plugins.area.show', ['code' => 'placard']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tabs.'.(count(app(PluginAreas::class)->find('placard')->area->pages)).'.id', 'settings')
            ->where('tabs.'.(count(app(PluginAreas::class)->find('placard')->area->pages)).'.current', false));
});

it('sends the old settings address to the area’s Settings tab', function (): void {
    ($this->start)();

    $this->actingAs($this->admin)->get(route('settings.show', ['group' => 'plugin.placard']))
        ->assertRedirect(route('plugins.area.settings', ['code' => 'placard']));
});

it('refuses the Settings tab to an editor who may open the area but not manage plugins', function (): void {
    ($this->start)();

    $this->actingAs($this->editor)->get(route('plugins.area.show', ['code' => 'placard']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tabs', fn ($tabs): bool => ! collect($tabs)->contains('id', 'settings')));

    $this->actingAs($this->editor)->get(route('plugins.area.settings', ['code' => 'placard']))
        ->assertForbidden();
});

it('saves from the Settings tab and returns there', function (): void {
    ($this->start)();
    $version = (int) DB::table('settings')->where('group_key', 'plugin.placard')->value('version');

    $this->actingAs($this->admin)
        ->put(route('settings.update', ['group' => 'plugin.placard']), [
            'values' => ['label_text' => 'Sponsored', 'fallback' => '', 'provider' => 'none', 'publisher_id' => '', 'slots' => []],
            'expected_version' => $version,
        ])
        ->assertRedirect(route('plugins.area.settings', ['code' => 'placard']));

    expect(app(SettingsReader::class)->get('plugin.placard', 'label_text'))->toBe('Sponsored');
});

it('writes nothing when the person may not manage plugins', function (): void {
    ($this->start)();
    $before = app(SettingsReader::class)->get('plugin.placard', 'label_text');
    $version = (int) DB::table('settings')->where('group_key', 'plugin.placard')->value('version');

    $this->actingAs($this->editor)
        ->put(route('settings.update', ['group' => 'plugin.placard']), [
            'values' => ['label_text' => 'Hacked', 'fallback' => '', 'provider' => 'none', 'publisher_id' => '', 'slots' => []],
            'expected_version' => $version,
        ])
        ->assertForbidden();

    expect(app(SettingsReader::class)->get('plugin.placard', 'label_text'))->toBe($before);
});

it('marks a plugin with an area as on its own page in Settings navigation', function (): void {
    ($this->start)();

    $entry = collect(app(SettingsNavigation::class)->forUser($this->admin->id))->firstWhere('key', 'plugin.placard');

    expect($entry)->toMatchArray([
        'ownPage' => true,
        'href' => route('plugins.area.settings', ['code' => 'placard'], false),
    ]);
});

it('points a Tools card whose to is settings at the area’s Settings tab', function (): void {
    $manifest = PluginFixtures::manifest('placard');
    $manifest['contributes']['admin'] = [[
        'surface' => 'tools',
        'id' => 'configure',
        'label' => 'Placard settings',
        'description' => 'How ads show.',
        'icon' => 'settings',
        'to' => 'settings',
    ]];
    ($this->start)(PluginFixtures::copy('placard', ['contributes' => $manifest['contributes']]));

    $card = collect(app(ToolsHub::class)->pagesFor($this->admin))->firstWhere('key', 'plugin.placard.configure');

    expect($card['href'])->toBe(route('plugins.area.settings', ['code' => 'placard'], false));
});

it('knows when a plugin has a Settings tab', function (): void {
    ($this->start)();

    expect(app(PluginAreas::class)->hasSettingsTab('placard'))->toBeTrue()
        ->and(app(PluginAreas::class)->hasSettingsTab('missing'))->toBeFalse();
});

it('sends a person who manages plugins but may not open the area to the settings group after saving', function (): void {
    ($this->start)();
    $version = (int) DB::table('settings')->where('group_key', 'plugin.placard')->value('version');
    $manager = User::factory()->create();
    $manager->givePermissionTo(Permission::findOrCreate('plugins.manage'));

    expect($manager->can('plugin.placard.manage'))->toBeFalse();

    $this->actingAs($manager)
        ->put(route('settings.update', ['group' => 'plugin.placard']), [
            'values' => ['label_text' => 'Sponsored', 'fallback' => '', 'provider' => 'none', 'publisher_id' => '', 'slots' => []],
            'expected_version' => $version,
        ])
        ->assertRedirect(route('settings.show', ['group' => 'plugin.placard']));

    expect(app(SettingsReader::class)->get('plugin.placard', 'label_text'))->toBe('Sponsored');
});

it('shows the Settings tab read-only, with no secret value, to a person who may only view settings', function (): void {
    $contributes = PluginFixtures::manifest('placard')['contributes'];
    $contributes['settings'][] = ['key' => 'api_key', 'type' => 'secret', 'label' => 'API key', 'description' => 'The ad network’s key.', 'section' => 'network'];
    ($this->start)(PluginFixtures::copy('placard', ['contributes' => $contributes]));

    $version = (int) DB::table('settings')->where('group_key', 'plugin.placard')->value('version');
    $this->actingAs($this->admin)
        ->put(route('settings.update', ['group' => 'plugin.placard']), [
            'values' => ['label_text' => 'Sponsored', 'fallback' => '', 'provider' => 'none', 'publisher_id' => '', 'slots' => []],
            'secrets' => ['api_key' => 'sk-live-do-not-show'],
            'expected_version' => $version,
        ])
        ->assertRedirect();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('settings.view'), Permission::findOrCreate('plugin.placard.manage'));

    expect($viewer->can('plugins.manage'))->toBeFalse();

    $response = $this->actingAs($viewer)->get(route('plugins.area.settings', ['code' => 'placard']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plugins/settings')
            ->where('readOnly.permission', fn ($name): bool => is_string($name) && $name !== '')
            ->where('secrets.api_key.isSet', true)
            ->missing('values.api_key'));

    expect($response->getContent())->not->toContain('sk-live-do-not-show');
});

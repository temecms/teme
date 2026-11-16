<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
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

    $this->start = function (string $folder = 'placard'): void {
        PluginFixtures::use($folder);
        $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
        app(PluginLoader::class)->load();
    };

    $this->save = function (array $values): void {
        $version = (int) DB::table('settings')->where('group_key', 'plugin.placard')->value('version');
        app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.placard', $values, [], [], $version, (string) $this->admin->id));
    };

    $this->errors = fn (array $settings, array $sections = []): array => app(ContributionsValidator::class)->errors(['settings' => $settings, 'settingsSections' => $sections], '1.0.0');
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('draws a plugin’s settings in its sections, each field with its condition', function (): void {
    ($this->start)();

    $this->actingAs($this->admin)->get(route('settings.show', ['group' => 'plugin.placard']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('panels', [
                ['title' => 'General', 'description' => 'How ads show everywhere.', 'keys' => ['label_text']],
                ['title' => 'Ad network', 'description' => '', 'keys' => ['provider', 'publisher_id']],
            ])
            ->where('fields.2.key', 'publisher_id')
            ->where('fields.2.visibleWhen', ['provider' => 'adsense'])
            ->where('fields.2.section', 'network'));
});

it('puts settings without a section in a first panel of their own, with no title', function (): void {
    $settings = PluginFixtures::manifest('placard')['contributes']['settings'];
    unset($settings[0]['section']);
    ($this->start)(PluginFixtures::copy('placard', ['contributes' => [...PluginFixtures::manifest('placard')['contributes'], 'settings' => $settings]]));

    $this->actingAs($this->admin)->get(route('settings.show', ['group' => 'plugin.placard']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('panels.0', ['title' => '', 'description' => '', 'keys' => ['label_text']])
            ->where('panels.1.title', 'Ad network'));
});

it('leaves a hidden setting as stored, and never checks it', function (): void {
    ($this->start)();
    ($this->save)(['provider' => 'adsense', 'publisher_id' => 'pub-123']);

    // Publisher ID is required, but hidden while the provider is None: neither checked nor changed.
    ($this->save)(['provider' => 'none', 'publisher_id' => '']);

    $reader = app(SettingsReader::class);

    expect($reader->get('plugin.placard', 'provider'))->toBe('none')
        ->and($reader->get('plugin.placard', 'publisher_id'))->toBe('pub-123');
});

it('checks a setting that shows', function (): void {
    ($this->start)();

    expect(fn () => ($this->save)(['provider' => 'adsense', 'publisher_id' => '']))
        ->toThrow(ValidationException::class);
});

it('refuses a condition or a section the manifest can’t keep, in one plain sentence each', function (array $settings, array $sections, string $error): void {
    expect(($this->errors)($settings, $sections))->toContain($error);
})->with([
    'a later key' => [[
        ['key' => 'publisher_id', 'type' => 'text', 'label' => 'Publisher ID', 'description' => '', 'visibleWhen' => ['provider' => 'adsense']],
        ['key' => 'provider', 'type' => 'select', 'label' => 'Provider', 'description' => '', 'options' => ['none' => 'None', 'adsense' => 'AdSense']],
    ], [], 'The setting “publisher_id” shows when “provider” has a value, and “provider” must be declared before it.'],
    'an unknown option' => [[
        ['key' => 'provider', 'type' => 'select', 'label' => 'Provider', 'description' => '', 'options' => ['none' => 'None', 'adsense' => 'AdSense']],
        ['key' => 'publisher_id', 'type' => 'text', 'label' => 'Publisher ID', 'description' => '', 'visibleWhen' => ['provider' => 'other']],
    ], [], 'The setting “publisher_id” shows when “provider” is “other”, which isn’t one of its options.'],
    'an unknown section' => [[
        ['key' => 'label_text', 'type' => 'text', 'label' => 'Label', 'description' => '', 'section' => 'nowhere'],
    ], [['id' => 'general', 'label' => 'General']], 'The setting “label_text” names the section “nowhere”, which the plugin doesn’t declare.'],
    'too many sections' => [[], array_map(fn (int $n): array => ['id' => "s{$n}", 'label' => "S{$n}"], range(1, 9)), 'A plugin’s settings have at most 8 sections.'],
]);

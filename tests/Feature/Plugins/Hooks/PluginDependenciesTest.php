<?php

declare(strict_types=1);

use App\Domain\Plugins\Dependencies;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PluginFixtures::loadFromOriginal('relay');
    app()->instance(Elevation::class, new OperatorElevation);

    /** The enable request for a plugin's folder, as the consent dialog posts it. */
    $this->input = function (string $path): EnablePluginInput {
        $manifest = json_decode((string) file_get_contents($path.'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);

        return new EnablePluginInput($manifest['name'], $manifest['version'], $manifest['capabilities'] ?? [], $manifest['hosts'] ?? [], $manifest['hooks'] ?? [], null);
    };

    $this->enable = fn (string $path): string => app(EnablePlugin::class)->execute(($this->input)($path));
    $this->disable = fn (string $name): string => app(DisablePlugin::class)->execute(new DisablePluginInput($name, null));
    $this->state = fn (string $name): ?string => PluginRecord::query()->where('name', $name)->value('state');
    $this->row = fn (string $name): mixed => collect(app(PluginList::class)->all())->firstWhere('name', $name);
});

afterEach(fn () => PluginFixtures::forget());

it('refuses to enable a plugin before the plugin it requires, and writes nothing', function (): void {
    PluginFixtures::use('relay', 'relay-pro');

    expect(fn () => ($this->enable)(PluginFixtures::path('relay-pro')))
        ->toThrow(ValidationException::class, 'It needs tests/relay ^1.0 enabled first.');
    expect(PluginRecord::query()->count())->toBe(0);
});

it('refuses to enable a plugin while the plugin it requires is at a version it doesn’t accept, and writes nothing', function (): void {
    $relay = PluginFixtures::copy('relay', ['version' => '2.0.0']);
    PluginFixtures::use($relay, 'relay-pro');
    ($this->enable)($relay);

    expect(fn () => ($this->enable)(PluginFixtures::path('relay-pro')))
        ->toThrow(ValidationException::class, 'It needs tests/relay ^1.0; 2.0.0 is installed.');
    expect(($this->state)('tests/relay-pro'))->toBeNull();
});

it('enables a plugin once the plugin it requires is enabled', function (): void {
    PluginFixtures::use('relay', 'relay-pro');
    ($this->enable)(PluginFixtures::path('relay'));
    ($this->enable)(PluginFixtures::path('relay-pro'));

    expect(($this->state)('tests/relay-pro'))->toBe('enabled');
});

it('refuses to disable a plugin another enabled plugin requires, and writes nothing', function (): void {
    PluginFixtures::use('relay', 'relay-pro');
    ($this->enable)(PluginFixtures::path('relay'));
    ($this->enable)(PluginFixtures::path('relay-pro'));

    expect(fn () => ($this->disable)('tests/relay'))
        ->toThrow(ValidationException::class, 'Relay Pro needs it. Disable Relay Pro first.');
    expect(($this->state)('tests/relay'))->toBe('enabled');

    ($this->disable)('tests/relay-pro');
    ($this->disable)('tests/relay');

    expect(($this->state)('tests/relay'))->toBe('disabled');
});

it('refuses to enable a plugin that listens to a plugin’s hooks without requiring it', function (): void {
    $pro = PluginFixtures::copy('relay-pro', ['requires' => ['teme' => '^0.5']]);
    PluginFixtures::use('relay', $pro);
    ($this->enable)(PluginFixtures::path('relay'));

    expect(fn () => ($this->enable)($pro))
        ->toThrow(ValidationException::class, 'It listens to relay:PromptModules, but doesn’t list tests/relay under requires.plugins.');
    expect(($this->state)('tests/relay-pro'))->toBeNull();
});

it('stops a plugin, unheard, when the plugin it requires is updated to a version it doesn’t accept', function (): void {
    PluginFixtures::use('relay', 'relay-pro');
    ($this->enable)(PluginFixtures::path('relay'));
    ($this->enable)(PluginFixtures::path('relay-pro'));

    $updated = PluginFixtures::copy('relay', ['version' => '2.0.0']);
    PluginFixtures::use($updated, 'relay-pro');
    app(PluginLoader::class)->load();

    expect(($this->row)('tests/relay-pro')?->state)->toBe(PluginState::CantBeUsed)
        ->and(($this->row)('tests/relay-pro')?->reason)->toBe('It needs tests/relay ^1.0; 2.0.0 is installed.')
        ->and(app(LoadedPlugins::class)->running())->toHaveKey('tests/relay')
        ->not->toHaveKey('tests/relay-pro');
});

it('lists what each plugin requires and is required by on teme:plugins', function (): void {
    PluginFixtures::use('relay', 'relay-pro');
    ($this->enable)(PluginFixtures::path('relay'));
    ($this->enable)(PluginFixtures::path('relay-pro'));

    Artisan::call('teme:plugins');
    $output = Artisan::output();

    expect($output)->toContain('Requires')
        ->toContain('Required by')
        ->toMatch('/tests\/relay-pro\s*\|[^\n]*\|\s*tests\/relay \^1\.0\s*\|/')
        ->toMatch('/tests\/relay\s*\|[^\n]*\|\s*\|\s*tests\/relay-pro\s*\|/')
        ->and(($this->row)('tests/relay')?->requiredBy)->toBe(['tests/relay-pro']);
});

it('shows the refusal on the Plugins page when disabling a plugin another one needs, naming it once', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    PluginFixtures::use('relay', 'relay-pro');
    ($this->enable)(PluginFixtures::path('relay'));
    ($this->enable)(PluginFixtures::path('relay-pro'));
    app()->forgetInstance(Elevation::class);

    $this->actingAs($admin)->post('/admin/plugins/relay/disable')
        ->assertRedirect(route('plugins.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Relay Pro needs it. Disable Relay Pro first.');

    expect(($this->state)('tests/relay'))->toBe('enabled');
});

it('refuses plugins that require each other, naming the circle', function (): void {
    $relay = PluginFixtures::copy('relay', ['requires' => ['teme' => '^0.5', 'plugins' => ['tests/relay-pro' => '^1.0']]]);
    PluginFixtures::use($relay, 'relay-pro');
    $found = app(PluginRegistry::class)->find('tests/relay');

    expect(app(Dependencies::class)->problems($found->manifest))
        ->toContain('Its requires.plugins go in a circle: tests/relay → tests/relay-pro → tests/relay. Neither could be enabled or disabled first.');
});

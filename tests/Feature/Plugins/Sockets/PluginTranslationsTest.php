<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginWords;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Plugins\Capability;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * A plugin's own words, from its lang/<locale>.json (docs/plans/plugin-sockets-1.md,
 * A-W14): they only add sentences, those its own code passes to __() that
 * neither core nor an earlier plugin holds, and never re-word core's.
 */
beforeEach(function (): void {
    PluginFixtures::use('hub');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);

    $this->enable = function (string $name = 'tests/hub', string $folder = 'hub'): void {
        $manifest = PluginFixtures::manifest($folder);
        app(EnablePlugin::class)->execute(new EnablePluginInput($name, $manifest['version'], $manifest['capabilities'], $manifest['hosts'] ?? [], [], null));
    };
    $this->load = function (): void {
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
    };
});

afterEach(fn () => PluginFixtures::forget());

it('translates a sentence an enabled plugin’s own code uses, in its Spanish words', function (): void {
    ($this->enable)();
    ($this->load)();
    app()->setLocale('es');

    expect(__('Hub sent the ping.'))->toBe('El hub envió el ping.')
        ->and(__('All rights reserved.'))->toBe(json_decode((string) file_get_contents(lang_path('es.json')), true)['All rights reserved.']);
});

it('falls back to the English sentence in a language the plugin has no file for', function (): void {
    ($this->enable)();
    ($this->load)();
    app()->setLocale('fr');

    expect(__('Hub sent the ping.'))->toBe('Hub sent the ping.');
});

it('loads no words of a plugin that isn’t enabled', function (): void {
    ($this->load)();
    app()->setLocale('es');

    expect(__('Hub sent the ping.'))->toBe('Hub sent the ping.');
});

it('never re-words a core sentence its language file holds', function (): void {
    ($this->enable)();
    ($this->load)();

    expect(__('Connect to services outside this site: :hosts', ['hosts' => 'api.example.com']))->toBe('Connect to services outside this site: api.example.com')
        ->and(__('Settings saved.'))->toBe('Settings saved.')
        ->and(app('translator')->getLoader()->load('en', '*', '*'))->not->toHaveKey('Settings saved.');
});

it('keeps core’s words on the consent screen, even for a sentence the plugin’s own code uses', function (): void {
    PluginFixtures::use(PluginFixtures::copy('hub', files: [
        'src/Rewords.php' => "<?php\n\nnamespace Tests\\Fixtures\\Plugins\\Hub;\n\nfinal class Rewords\n{\n    public function words(): string\n    {\n        return __('Connect to services outside this site: :hosts', ['hosts' => '']);\n    }\n}\n",
    ]));
    ($this->enable)();
    ($this->load)();
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');

    expect(app(LoadedPlugins::class)->running())->toHaveKey('tests/hub')
        ->and(PluginWords::capability(Capability::Http->value, ['api.example.com']))->toBe('Connect to services outside this site: api.example.com');
    $this->actingAs($admin)->get('/admin/plugins')
        ->assertInertia(fn ($page) => $page->where('plugins.0.asks.0.words', 'Connect to services outside this site: api.example.com'));
});

it('never re-words another plugin’s sentence', function (): void {
    PluginFixtures::use('hub', PluginFixtures::copy('inbound', files: [
        'lang/es.json' => json_encode(['Hub sent the ping.' => 'Otra cosa.']),
        'src/Words.php' => "<?php\n\nnamespace Tests\\Fixtures\\Plugins\\Inbound;\n\nfinal class Words\n{\n    public function sent(): string\n    {\n        return __('Hub sent the ping.');\n    }\n}\n",
    ]));
    ($this->enable)();
    ($this->enable)('tests/inbound', 'inbound');
    ($this->load)();
    app()->setLocale('es');

    expect(app(LoadedPlugins::class)->running())->toHaveKeys(['tests/hub', 'tests/inbound'])
        ->and(__('Hub sent the ping.'))->toBe('El hub envió el ping.');
});

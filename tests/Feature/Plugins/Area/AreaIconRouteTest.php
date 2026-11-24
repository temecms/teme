<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create()->assignRole('author');
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $this->start = function (array $manifest = [], array $files = []): void {
        PluginFixtures::use(PluginFixtures::copy('placard', $manifest, files: $files));
        $found = app(PluginRegistry::class)->find('tests/placard');
        app(EnablePlugin::class)->execute(new EnablePluginInput(
            'tests/placard',
            $found?->manifest->version() ?? '1.0.0',
            $found?->manifest->capabilities() ?? [],
            $found?->manifest->hosts() ?? [],
            $found?->manifest->hooks() ?? [],
            null,
        ));
        app(PluginLoader::class)->load();
    };
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('serves the area’s plugin icon to an editor who may open the area', function (): void {
    ($this->start)(['icon' => 'art/icon.png'], ['art/icon.png' => $this->png]);

    $this->actingAs($this->editor)->get(route('plugins.area.show', ['code' => 'placard']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('plugin.icon', fn (mixed $icon): bool => is_string($icon) && str_starts_with($icon, route('plugins.area.icon', 'placard'))));

    $this->actingAs($this->editor)->get(route('plugins.area.icon', 'placard'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('puts null on the frame and answers not found when the icon file is missing', function (): void {
    ($this->start)(['icon' => 'art/icon.png']);

    $this->actingAs($this->editor)->get(route('plugins.area.show', ['code' => 'placard']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('plugin.icon', null));

    $this->actingAs($this->editor)->get(route('plugins.area.icon', 'placard'))
        ->assertNotFound();
});

it('refuses the area’s icon to an author who may not open the area', function (): void {
    ($this->start)(['icon' => 'art/icon.png'], ['art/icon.png' => $this->png]);

    $this->actingAs($this->author)->get(route('plugins.area.icon', 'placard'))
        ->assertForbidden();
});

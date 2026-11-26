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

afterEach(function (): void {
    PluginFixtures::forget();
});

it('puts a plugin’s menu entry in the section its manifest names', function (string $menu, string $section): void {
    $contributes = PluginFixtures::manifest('placard')['contributes'];
    $contributes['area']['menu'] = $menu;
    ($this->start)(['contributes' => $contributes]);

    $this->actingAs($this->editor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nav', fn (mixed $nav): bool => collect($nav)->firstWhere('key', 'plugin.placard')['section'] === $section));
})->with([
    'content' => ['content', 'content'],
    'site' => ['site', 'site'],
]);

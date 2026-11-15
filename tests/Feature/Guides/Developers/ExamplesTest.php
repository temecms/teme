<?php

declare(strict_types=1);

use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Themes\ThemeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use TemeExamples\FirstPlugin\FirstPlugin;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.version' => '0.8.0-alpha.1']);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->editor = User::factory()->create()->assignRole('editor');
});

afterEach(fn () => PluginFixtures::forget());

it('finds, validates, enables and runs the first-plugin example', function (): void {
    // Discovery only accepts folders that hold a manifest (B-01): the example
    // package itself, under docs/guides/developers/examples/plugins/.
    $path = base_path('docs/guides/developers/examples/plugins/first-plugin');
    PluginFixtures::use($path);
    config(['teme.version' => '0.8.0-alpha.1']);
    app(PluginRegistry::class)->flush();

    $found = app(PluginRegistry::class)->find('teme-examples/first-plugin');

    expect($found)->not->toBeNull()
        ->and($found->usable())->toBeTrue()
        ->and($found->problems)->toBe([]);

    $this->artisan('teme:plugins:enable', [
        'name' => 'teme-examples/first-plugin',
        '--agree' => true,
    ])->assertSuccessful();

    require_once $path.'/src/FirstPlugin.php';
    FirstPlugin::$heard = [];
    app(PluginLoader::class)->load();
    $item = lifecycleDraft($this->editor, 'Handbook example');
    listsPublish($item, $this->editor);
    $item->refresh();

    expect(FirstPlugin::$heard)->toHaveCount(1)
        ->and(FirstPlugin::$heard[0])->toContain($item->id);
});

it('checks and draws the first-theme example', function (): void {
    $path = base_path('docs/guides/developers/examples/themes/first-theme');
    config(['teme.theme_paths' => [$path]]);
    app(ThemeRegistry::class)->flush();
    $this->app['env'] = 'local';

    $this->artisan('teme:theme:check', ['code' => 'first-theme'])->assertSuccessful();

    themeActivate('first-theme');
    addrPublished($this->editor, 'Handbook post');

    addrGet('/')
        ->assertOk()
        ->assertSee('data-template="index"', false);

    addrGet('/handbook-post/')
        ->assertOk()
        ->assertSee('data-template="single"', false)
        ->assertSee('Handbook post');
});

<?php

declare(strict_types=1);

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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->author = User::factory()->create()->assignRole('author');
    $this->admin = User::factory()->create()->assignRole('administrator');

    PluginFixtures::use('columns');
    $manifest = app(PluginRegistry::class)->all()['tests/columns']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/columns',
        $manifest->version(),
        $manifest->capabilities(),
        $manifest->hosts(),
        $manifest->hooks(),
        null,
    ));
    app(PluginLoader::class)->load();
});

afterEach(fn () => PluginFixtures::forget());

it('offers the type\'s plugin columns and sends values only for those the person shows', function (): void {
    $post = lifecycleDraft($this->author, 'Listed');
    lscrPrefer($this->author, ['lists.post.columns' => ['plugin:columns:score']]);

    $this->actingAs($this->author)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('pluginColumns', 3)
            ->where('pluginColumns.0.key', 'plugin:columns:score')
            ->where('pluginColumns.1.key', 'plugin:columns:views')
            ->where('pluginColumns.2.key', 'plugin:columns:note')
            ->has('pluginCells', 1)
            ->where('pluginCells.plugin:columns:score.'.$post->id.'.kind', 'badge')
            ->where('pluginCells.plugin:columns:score.'.$post->id.'.value', 'Good')
            ->where('pluginCells.plugin:columns:score.'.$post->id.'.tone', 'ok'));
});

it('sends no plugin values to a person who hasn\'t chosen any', function (): void {
    lifecycleDraft($this->author, 'Listed');

    $this->actingAs($this->author)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('pluginColumns', 3)
            ->where('pluginCells', []));
});

it('still lists the page when a plugin column throws, and names the plugin', function (): void {
    lifecycleDraft($this->author, 'Listed');
    lscrPrefer($this->author, ['lists.post.columns' => ['plugin:columns:note', 'author']]);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput(
        'plugin.columns',
        ['mode' => 'throw'],
        [],
        [],
        0,
        (string) $this->admin->id,
    ));

    $this->actingAs($this->author)->get(route('content.index', ['type' => 'posts']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pluginColumnProblems', ['Columns'])
            ->has('rows', 1)
            ->missing('pluginCells.plugin:columns:note'));
});

it('sends markup from a plugin as the text it is', function (): void {
    $post = lifecycleDraft($this->author, 'Listed');
    lscrPrefer($this->author, ['lists.post.columns' => ['plugin:columns:note']]);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput(
        'plugin.columns',
        ['mode' => 'markup'],
        [],
        [],
        0,
        (string) $this->admin->id,
    ));

    $this->actingAs($this->author)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'pluginCells.plugin:columns:note.'.$post->id.'.value',
                '<img src=x onerror=alert(1)>',
            ));
});

it('draws plugin cells without dangerouslySetInnerHTML', function (): void {
    $index = (string) file_get_contents(resource_path('js/pages/content/index.tsx'));
    $cell = (string) file_get_contents(resource_path('js/components/list/plugin-cell.tsx'));

    expect($index)->not->toContain('dangerouslySetInnerHTML')
        ->and($cell)->not->toContain('dangerouslySetInnerHTML');
});

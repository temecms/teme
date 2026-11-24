<?php

declare(strict_types=1);

use App\Domain\Navigation\Queries\AdminNavigation;
use App\Domain\Navigation\ToolsHub;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    $this->editor = User::factory()->create()->assignRole('editor');

    // Areas from copies of Placard, each with a name and code of its own and no table, enabled a minute apart.
    $this->areas = function (array $menus): void {
        $folders = [];

        foreach (array_values($menus) as $index => $menu) {
            $letter = chr(ord('a') + $index);
            $manifest = PluginFixtures::manifest('placard');
            $manifest['contributes']['area']['menu'] = $menu;
            $manifest['contributes']['area']['label'] = 'Area '.strtoupper($letter);
            $folder = PluginFixtures::copy('placard', [
                'name' => "tests/placard-{$letter}",
                'code' => "placard{$letter}",
                'label' => 'Placard '.strtoupper($letter),
                'owns' => ['tables' => []],
                'contributes' => $manifest['contributes'],
            ], ['name' => "tests/placard-{$letter}"]);
            (new Filesystem)->deleteDirectory($folder.'/database');
            $folders[] = $folder;
        }

        PluginFixtures::use(...$folders);

        foreach (array_keys(array_values($menus)) as $index) {
            $name = 'tests/placard-'.chr(ord('a') + $index);
            Carbon::setTestNow(now()->addMinute());
            $manifest = app(PluginRegistry::class)->find($name)?->manifest;
            app(EnablePlugin::class)->execute(new EnablePluginInput($name, $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
        }

        Carbon::setTestNow();
        app(PluginLoader::class)->load();
    };

    $this->menu = fn (User $person): array => array_values(array_filter(
        app(AdminNavigation::class)->forUser($person->id),
        fn (array $entry): bool => str_starts_with($entry['key'], 'plugin.'),
    ));

    $this->cards = fn (User $person): array => array_values(array_filter(
        app(ToolsHub::class)->pagesFor($person),
        fn (array $page): bool => str_ends_with($page['key'], '.area'),
    ));
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('gives five areas a menu entry each, under the area’s own label in the section it names, and the sixth a Tools card', function (): void {
    ($this->areas)(['site', 'content', 'site', 'site', 'site', 'content']);

    $menu = ($this->menu)($this->editor);

    expect(array_column($menu, 'label'))->toEqualCanonicalizing(['Area A', 'Area B', 'Area C', 'Area D', 'Area E'])
        ->and(array_column(array_filter($menu, fn (array $entry): bool => $entry['label'] === 'Area B'), 'section'))->toBe(['content'])
        ->and(array_column(array_filter($menu, fn (array $entry): bool => $entry['label'] === 'Area A'), 'href'))->toBe(['/admin/plugin/placarda'])
        ->and(($this->cards)($this->editor))->toBe([[
            'key' => 'plugin.placardf.area',
            'label' => 'Area F',
            'icon' => 'plug',
            'description' => 'Ads placed in articles and around the site, and how they do.',
            'href' => '/admin/plugin/placardf',
            'external' => false,
            'plugin' => 'Placard F',
        ]]);
});

it('puts an area that asks for Tools on the Tools hub, leaving its menu place to the next', function (): void {
    ($this->areas)(['tools', 'site']);

    expect(array_column(($this->menu)($this->editor), 'label'))->toBe(['Area B'])
        ->and(array_column(($this->cards)($this->editor), 'label'))->toBe(['Area A'])
        ->and(array_column(($this->cards)($this->editor), 'plugin'))->toBe(['Placard A']);
});

it('names Tools as the parent of an area placed there, and no parent for one in the menu', function (): void {
    ($this->areas)(['tools', 'site']);

    $this->actingAs($this->editor)->get('/admin/plugin/placarda')
        ->assertInertia(fn (Assert $page) => $page->where('parent', ['title' => 'Tools', 'href' => '/admin/tools']));
    $this->actingAs($this->editor)->get('/admin/plugin/placardb')
        ->assertInertia(fn (Assert $page) => $page->where('parent', null));
});

it('shows neither the entry nor the card to a person without the area’s permission', function (): void {
    ($this->areas)(['site', 'site', 'site', 'site', 'site', 'site']);
    $author = User::factory()->create()->assignRole('author');

    expect(($this->menu)($author))->toBe([])
        ->and(($this->cards)($author))->toBe([]);
});

<?php

/**
 * Tests, in a real browser, the cascade of a plugin card's stylesheet: the plugin's
 * sheet is served in the teme.plugins layer, and a theme rule in teme.theme beats it.
 * The theme is a copy of the open fixture, so no shared fixture changes.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->note = fn (): array => ['type' => 'plugin-card', 'attrs' => ['id' => (string) Str::ulid(), 'card' => 'cardbox:note', 'values' => ['body' => 'Hi']]];
});

afterEach(function (): void {
    PluginFixtures::forget();
    File::deleteDirectory(storage_path('framework/testing/themes'));
});

/**
 * Enables the cardbox fixture on a copy, and draws its note on a page with the open theme,
 * optionally with a copy of that theme whose template gives a rule in teme.theme.
 */
function drawPluginCard(string $themeCopy = ''): void
{
    if ($themeCopy !== '') {
        config(['teme.theme_paths' => [$themeCopy]]);
    } else {
        config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/open')]]);
    }

    themeActivate('open');
    PluginFixtures::use(PluginFixtures::copy('cardbox'));
    $manifest = app(PluginRegistry::class)->all()['tests/cardbox']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/cardbox', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), [], null));
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();

    $editor = User::factory()->create()->assignRole('editor');
    $region = regionCreate($editor, 'open', 'sidebar');
    regionSave($region, $editor, [['type' => 'plugin-card', 'attrs' => ['id' => (string) Str::ulid(), 'card' => 'cardbox:note', 'values' => ['body' => 'Hi']]]]);
    listsPublish($region, $editor);
    pageArticle($editor, 'A story');
}

/**
 * A copy of the open theme whose single template also gives a rule for the note, in teme.theme.
 */
function themeWithNoteRule(): string
{
    // A theme's folder is named for its code, so the copy's parent holds a folder named open.
    $parent = storage_path('framework/testing/themes/'.bin2hex(random_bytes(6)));
    $copy = $parent.'/open';
    File::copyDirectory(base_path('tests/Fixtures/themes/open'), $copy);
    File::put($copy.'/views/templates/single.blade.php', '<x-teme::document><main id="main" data-template="single">{{ $page->title }}<style>@layer teme.theme{.card-plugin-cardbox-note{border-color:rgb(0, 0, 255)}}</style><x-teme::region name="sidebar" /><x-teme::region name="narrow" /><x-teme::region name="own" /></main></x-teme::document>');

    return $copy;
}

it('draws the plugin’s own colour when the theme gives no rule', function (): void {
    drawPluginCard();

    visit('/a-story/')
        ->assertScript("getComputedStyle(document.querySelector('.card-plugin-cardbox-note')).borderTopColor === 'rgb(204, 0, 0)'", true);
});

it('lets a theme rule in teme.theme beat the plugin’s stylesheet', function (): void {
    drawPluginCard(themeWithNoteRule());

    visit('/a-story/')
        ->assertScript("getComputedStyle(document.querySelector('.card-plugin-cardbox-note')).borderTopColor === 'rgb(0, 0, 255)'", true);
});

<?php

/**
 * Tests that a plugin's stylesheet for a card is linked into the head of a
 * page that draws that card, and of no other page, and never while the plugin
 * is disabled.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/open')]]);
    themeActivate('open');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new OperatorElevation);

    $this->restart = function (): void {
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
    };
    $this->enable = function (): void {
        PluginFixtures::use(PluginFixtures::copy('cardbox'));
        $manifest = app(PluginRegistry::class)->all()['tests/cardbox']->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/cardbox', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), [], null));
        ($this->restart)();
    };
    $this->note = fn (): array => ['type' => 'plugin-card', 'attrs' => ['id' => (string) Str::ulid(), 'card' => 'cardbox:note', 'values' => ['body' => 'Hi']]];
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('links a plugin’s stylesheet for a card into a page that draws the card', function (): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');
    regionSave($region, $this->editor, [($this->note)()]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');

    expect(addrGet('/a-story/')->assertOk()->getContent())
        ->toContain('/_teme/plugins/cardbox/dist/note.css?v=')
        ->toContain('data-teme-plugin-style="cardbox:card:note"');
});

it('does not link a stylesheet on a page that draws no card of the plugin', function (): void {
    ($this->enable)();
    pageArticle($this->editor, 'A quiet story');

    expect(addrGet('/a-quiet-story/')->assertOk()->getContent())
        ->not->toContain('data-teme-plugin-style');
});

it('does not link a stylesheet while its plugin is disabled', function (): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');
    regionSave($region, $this->editor, [($this->note)()]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/cardbox', null));
    ($this->restart)();

    expect(addrGet('/a-story/')->assertOk()->getContent())
        ->not->toContain('data-teme-plugin-style');
});

it('does not link a stylesheet the active theme switches off', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/styles-off')]]);
    themeActivate('styles-off');
    ($this->enable)();
    $region = regionCreate($this->editor, 'styles-off', 'sidebar');
    regionSave($region, $this->editor, [($this->note)()]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');

    expect(addrGet('/a-story/')->assertOk()->getContent())
        ->not->toContain('data-teme-plugin-style');
});

it('keeps the link on a real page-cache hit', function (): void {
    $folder = cacheOn();
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');
    regionSave($region, $this->editor, [($this->note)()]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');

    $first = addrGet('/a-story/')->assertOk();
    $second = addrGet('/a-story/')->assertOk();

    expect($first->headers->get('X-Teme-Cache'))->not->toBe('HIT')
        ->and($second->headers->get('X-Teme-Cache'))->toBe('HIT')
        ->and($second->getContent())->toContain('data-teme-plugin-style="cardbox:card:note"');

    cacheOff($folder);
});

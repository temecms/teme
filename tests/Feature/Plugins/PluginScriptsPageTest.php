<?php

/**
 * Tests that a plugin's declared script for a card is served and linked as a module
 * after the body only on a page that draws that card, and never without the site.scripts
 * capability or while the theme switches it off.
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    // The cardbox fixture, with a built script for its note card and the site.scripts capability.
    $this->enable = function (bool $asked = true): void {
        $cardbox = json_decode((string) file_get_contents(PluginFixtures::path('cardbox').'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $contributes = [...$cardbox['contributes'], 'scripts' => [['output' => 'card:note', 'file' => 'dist/note.js']]];
        $capabilities = $asked ? ['pages.insert', 'site.scripts'] : ['pages.insert'];

        PluginFixtures::use(PluginFixtures::copy('cardbox', ['capabilities' => $capabilities, 'contributes' => $contributes], [], [
            'dist/note.js' => 'document.documentElement.dataset.note = "1";',
        ]));
        $manifest = app(PluginRegistry::class)->all()['tests/cardbox']->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/cardbox', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), [], null));
        ($this->restart)();
    };
    $this->note = fn (): array => ['type' => 'plugin-card', 'attrs' => ['id' => (string) Str::ulid(), 'card' => 'cardbox:note', 'values' => ['body' => 'Hi']]];
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('links a plugin’s script as a deferred module near the end of the body on a page that draws its card', function (): void {
    ($this->enable)();
    $region = regionCreate($this->editor, 'open', 'sidebar');
    regionSave($region, $this->editor, [($this->note)()]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');

    $html = addrGet('/a-story/')->assertOk()->getContent();

    expect($html)
        ->toMatch('#<script type="module" defer src="[^"]*/_teme/plugin-scripts/cardbox/dist/note\.js\?v=[0-9a-f]+" data-teme-plugin-script="cardbox:card:note"></script>#')
        ->and(strpos($html, 'data-teme-plugin-script'))
        ->toBeGreaterThan(strpos($html, '<body'))
        ->toBeLessThan(strpos($html, '</body>'));
});

it('does not link a script on a page that draws no card of the plugin', function (): void {
    ($this->enable)();
    pageArticle($this->editor, 'A quiet story');

    expect(addrGet('/a-quiet-story/')->assertOk()->getContent())
        ->not->toContain('data-teme-plugin-script');
});

it('refuses at enable a plugin that declares scripts without the site.scripts capability', function (): void {
    expect(fn () => ($this->enable)(false))->toThrow(ValidationException::class);
});

it('keeps the script link on a real page-cache hit', function (): void {
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
        ->and($second->getContent())->toContain('data-teme-plugin-script="cardbox:card:note"');

    cacheOff($folder);
});

it('answers 404 for a script the plugin does not declare', function (): void {
    ($this->enable)();

    addrGet('/_teme/plugin-scripts/cardbox/dist/other.js')->assertNotFound();
});

it('serves a declared script as javascript with no sniffing', function (): void {
    ($this->enable)();

    $response = addrGet('/_teme/plugin-scripts/cardbox/dist/note.js')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/javascript')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->getContent())->toContain('dataset.note');
});

<?php

/**
 * Tests that a plugin's stylesheet for a page insertion is linked into the
 * head of a page that draws that insertion, and that a page served from the
 * page cache carries the link on a real cache hit.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use(PluginFixtures::copy('billboard'));
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    app()->instance(Elevation::class, new OperatorElevation);

    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();

    $this->author = User::factory()->create()->assignRole('author');
    $this->folder = cacheOn();
    pageArticle($this->author, 'Harbour story');
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();

    if (isset($this->folder)) {
        cacheOff($this->folder);
    }
});

it('links a plugin’s stylesheet for an insertion a page draws', function (): void {
    expect(addrGet('/harbour-story/')->assertOk()->getContent())
        ->toContain('data-test="billboard-top"')
        ->toContain('data-teme-plugin-style="billboard:insertion:top"');
});

it('keeps the link on a real page-cache hit', function (): void {
    $first = addrGet('/harbour-story/')->assertOk();
    expect($first->headers->get('X-Teme-Cache'))->not->toBe('HIT');

    $second = addrGet('/harbour-story/')->assertOk();

    expect($second->headers->get('X-Teme-Cache'))->toBe('HIT')
        ->and($second->getContent())->toContain('data-teme-plugin-style="billboard:insertion:top"');
});

it('links an insertion’s stylesheet once, in the head, for an insertion the body draws', function (): void {
    $html = addrGet('/harbour-story/')->assertOk()->getContent();
    $head = strpos($html, '</head>');

    expect(substr_count($html, 'data-teme-plugin-style="billboard:insertion:top"'))->toBe(1)
        ->and(strpos($html, 'data-teme-plugin-style="billboard:insertion:top"'))->toBeLessThan($head);
});

it('links the stylesheet of a fixed-edge insertion too', function (): void {
    $original = PluginFixtures::manifest('billboard');
    $contributes = $original['contributes'];
    $contributes['styles'] = [['output' => 'insertion:bar', 'file' => 'dist/bar.css']];
    PluginFixtures::use(PluginFixtures::copy('billboard', ['contributes' => $contributes], [], ['dist/bar.css' => '.billboard-bar{color:red}']));
    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();

    expect(addrGet('/harbour-story/')->assertOk()->getContent())
        ->toContain('data-teme-plugin-style="billboard:insertion:bar"');
});

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
use Illuminate\Support\Facades\File;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PagePosition;
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

/**
 * Copies the billboard with its own manifest contributes and files, enables it and loads it again.
 *
 * @param  array<string, mixed>  $contributes
 * @param  array<string, string>  $files
 */
function billboardWith(array $contributes, array $files): void
{
    PluginFixtures::use(PluginFixtures::copy('billboard', $contributes === [] ? [] : ['contributes' => $contributes], [], $files));
    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();
}

it('links no sheet for an insertion whose markup cleans to nothing', function (): void {
    Billboard::$gives = [new PageInsertion('billboard.top', PagePosition::bodyStart(), '<script>alert(1)</script>')];

    expect(addrGet('/harbour-story/')->assertOk()->getContent())
        ->not->toContain('data-teme-plugin-style="billboard:insertion:top"');
});

it('links a fixed-edge sheet only for the first insertion on its edge', function (): void {
    $contributes = PluginFixtures::manifest('billboard')['contributes'];
    $contributes['styles'] = [
        ['output' => 'insertion:bar', 'file' => 'dist/bar.css'],
        ['output' => 'insertion:second-bar', 'file' => 'dist/second.css'],
    ];
    billboardWith($contributes, ['dist/bar.css' => '.billboard-bar{color:red}', 'dist/second.css' => '.billboard-second-bar{color:blue}']);

    expect(addrGet('/harbour-story/')->assertOk()->getContent())
        ->toContain('data-teme-plugin-style="billboard:insertion:bar"')
        ->not->toContain('data-teme-plugin-style="billboard:insertion:second-bar"');
});

it('links no fixed-edge sheet on a theme whose manifest says it fixes no edges', function (): void {
    $folder = storage_path('framework/testing/themes/'.bin2hex(random_bytes(6)));
    File::copyDirectory(base_path('tests/Fixtures/themes/open'), $folder.'/open');
    $manifest = json_decode((string) file_get_contents($folder.'/open/teme-theme.json'), true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($folder.'/open/teme-theme.json', json_encode($manifest + ['fixed' => false], JSON_THROW_ON_ERROR));
    config(['teme.theme_paths' => [$folder.'/open']]);
    themeActivate('open');

    try {
        expect(addrGet('/harbour-story/')->assertOk()->getContent())
            ->not->toContain('data-teme-plugin-style="billboard:insertion:bar"');
    } finally {
        File::deleteDirectory($folder);
    }
});

it('links no sheet Teme refuses, so the page leaves it out', function (): void {
    billboardWith([], ['dist/top.css' => '@import url("x.css");.billboard-top{color:red}']);

    expect(addrGet('/harbour-story/')->assertOk()->getContent())
        ->toContain('data-test="billboard-top"')
        ->not->toContain('data-teme-plugin-style="billboard:insertion:top"');
});

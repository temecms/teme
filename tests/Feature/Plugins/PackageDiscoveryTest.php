<?php

declare(strict_types=1);

use App\Domain\Plugins\PackagesWithoutPlugins;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Tests\Fixtures\Plugins\PluginFixtures;

/*
 * A plugin reaches Teme only through its Plugin class: Laravel never
 * discovers a package of type teme-plugin, enabled or not (I-3353).
 */

beforeEach(function (): void {
    $this->base = storage_path('framework/testing/plugins/base-'.bin2hex(random_bytes(6)));
    (new Filesystem)->ensureDirectoryExists($this->base.'/vendor/composer');
    (new Filesystem)->ensureDirectoryExists($this->base.'/bootstrap/cache');
    file_put_contents($this->base.'/vendor/composer/installed.json', json_encode(['packages' => [
        ['name' => 'tests/autoloads', 'type' => 'teme-plugin', 'extra' => ['laravel' => ['providers' => ['Tests\\Fixtures\\Plugins\\Autoloads\\AutoloadsServiceProvider'], 'aliases' => ['Loud' => 'Tests\\Fixtures\\Plugins\\Autoloads\\Autoloads']]]],
        ['name' => 'acme/tool', 'type' => 'library', 'extra' => ['laravel' => ['providers' => ['Acme\\Tool\\ToolServiceProvider']]]],
    ]], JSON_THROW_ON_ERROR));
    $this->manifest = $this->base.'/bootstrap/cache/packages.php';
    $this->marker = sys_get_temp_dir().'/teme-discovered-plugin-'.getmypid();
    @unlink($this->marker);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->base);
    @unlink($this->marker);
    PluginFixtures::forget();
});

it('is the package manifest Teme boots with, and package:discover writes', function (): void {
    expect(app(PackageManifest::class))->toBeInstanceOf(PackagesWithoutPlugins::class);
});

it('never discovers a plugin’s providers or aliases, built afresh or read from a cache made before', function (): void {
    $built = new PackagesWithoutPlugins(new Filesystem, $this->base, $this->manifest);

    expect($built->providers())->toBe(['Acme\\Tool\\ToolServiceProvider'])
        ->and($built->aliases())->toBe([])
        ->and(array_keys(require $this->manifest))->toBe(['acme/tool']);

    // Laravel's own manifest, as a cache written before Teme's took its place.
    unlink($this->manifest);
    PluginFixtures::installed(['tests/autoloads' => 'autoloads']);
    (new PackageManifest(new Filesystem, $this->base, $this->manifest))->build();
    $cached = new PackagesWithoutPlugins(new Filesystem, $this->base, $this->manifest);

    expect(array_keys(require $this->manifest))->toBe(['tests/autoloads', 'acme/tool'])
        ->and($cached->providers())->toBe(['Acme\\Tool\\ToolServiceProvider'])
        ->and($cached->aliases())->toBe([]);

    foreach ($cached->providers() as $provider) {
        expect($provider)->not->toStartWith('Tests\\Fixtures\\Plugins');
    }

    expect(is_file($this->marker))->toBeFalse();
});

it('leaves a plugin out of a cached manifest from Composer’s runtime data, never reading installed.json', function (): void {
    (new PackageManifest(new Filesystem, $this->base, $this->manifest))->build();
    unlink($this->base.'/vendor/composer/installed.json');
    PluginFixtures::installed(['tests/autoloads' => 'autoloads']);

    $cached = new PackagesWithoutPlugins(new Filesystem, $this->base, $this->manifest);

    expect($cached->providers())->toBe(['Acme\\Tool\\ToolServiceProvider'])
        ->and($cached->aliases())->toBe([])
        ->and(is_file($this->base.'/vendor/composer/installed.json'))->toBeFalse();
});

it('still refuses a plugin that asks Laravel to load it, so its author learns why', function (): void {
    PluginFixtures::use('autoloads');

    expect(app(PluginRegistry::class)->find('tests/autoloads')?->problems)
        ->toContain('It asks Laravel to load it, so it would run even while disabled.');
});

it('reads what Composer installed, not the plugin’s own composer.json', function (): void {
    $clean = PluginFixtures::copy('hello', composer: ['name' => 'tests/hello']);
    $loud = PluginFixtures::copy('autoloads');
    PluginFixtures::use();
    PluginFixtures::installed(['tests/hello' => $clean, 'tests/autoloads' => $loud], [
        'tests/hello' => ['autoload' => ['psr-4' => ['Tests\\Fixtures\\Plugins\\Hello\\' => 'src/'], 'files' => ['src/Hello.php']]],
        'tests/autoloads' => ['autoload' => ['psr-4' => ['Tests\\Fixtures\\Plugins\\Autoloads\\' => 'src/']], 'extra' => []],
    ]);

    expect(app(PluginRegistry::class)->find('tests/hello')?->problems)->toBe(['It asks Composer to run files on every request, so it would run even while disabled.'])
        ->and(app(PluginRegistry::class)->find('tests/autoloads')?->problems)->toBe([]);
});

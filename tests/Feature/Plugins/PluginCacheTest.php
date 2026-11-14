<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\Queries\PluginRow;
use App\Domain\Plugins\Seams\HookSubscriptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\RecordingSubscriptions;

/*
 * The plugin cache stops the scanning, and never stands in for a plugin
 * that changed since it was written (I-3353).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(HookSubscriptions::class, new RecordingSubscriptions);
    PluginFixtures::use();
    $this->cache = storage_path('framework/testing/teme-plugins-'.bin2hex(random_bytes(6)).'.php');
});

afterEach(function (): void {
    @unlink($this->cache);
    PluginFixtures::forget();
});

it('answers from the cache without opening a plugin’s code: its class deleted, the same answer', function (): void {
    $hello = PluginFixtures::copy('hello');
    PluginFixtures::installed(['tests/hello' => $hello], references: ['tests/hello' => 'aaa111']);
    config(['teme.plugin_cache' => $this->cache]);
    Artisan::call('teme:plugins:cache');

    unlink($hello.'/src/Hello.php');
    app(PluginRegistry::class)->flush();

    expect(app(PluginRegistry::class)->find('tests/hello')?->problems)->toBe([])
        ->and(app(PluginRegistry::class)->find('tests/hello')?->usable())->toBeTrue();
});

it('never writes the cache from a request: one with no cache and one with a stale identity both read live', function (): void {
    $hello = PluginFixtures::copy('hello');
    PluginFixtures::installed(['tests/hello' => $hello], references: ['tests/hello' => 'aaa111']);
    config(['teme.plugin_cache' => $this->cache]);
    $left = fn (): array => glob($this->cache.'*') ?: [];

    $missing = PluginFixtures::serving('admin', fn () => app(PluginRegistry::class)->find('tests/hello'));

    expect($missing?->usable())->toBeTrue()
        ->and($left())->toBe([]);

    Artisan::call('teme:plugins:cache');
    $written = (string) file_get_contents($this->cache);
    unlink($hello.'/src/Hello.php');
    PluginFixtures::installed(['tests/hello' => $hello], references: ['tests/hello' => 'bbb222']);

    $stale = PluginFixtures::serving('admin', fn () => app(PluginRegistry::class)->find('tests/hello'));

    expect($stale?->usable())->toBeFalse()
        ->and($left())->toBe([$this->cache])
        ->and(file_get_contents($this->cache))->toBe($written);
});

it('writes the cache from teme:plugins:cache, whole, leaving no temporary file', function (): void {
    $hello = PluginFixtures::copy('hello');
    PluginFixtures::installed(['tests/hello' => $hello], references: ['tests/hello' => 'aaa111']);
    config(['teme.plugin_cache' => $this->cache]);

    $exit = Artisan::call('teme:plugins:cache');
    $cached = require $this->cache;

    expect($exit)->toBe(0)
        ->and(glob($this->cache.'*'))->toBe([$this->cache])
        ->and($cached['plugins'][0]['package'])->toBe('tests/hello');
});

it('reads a plugin again when Composer installed another commit, so a new host waits for consent', function (): void {
    $hello = PluginFixtures::copy('hello');
    PluginFixtures::installed(['tests/hello' => $hello], references: ['tests/hello' => 'aaa111']);
    config(['teme.plugin_cache' => $this->cache]);
    Artisan::call('teme:plugins:cache');
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/hello',
        'code' => 'hello',
        'version' => '1.0.0',
        'consent' => ['capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished']],
    ]);

    $manifest = [...PluginFixtures::manifest('hello'), 'version' => '1.1.0', 'capabilities' => ['content.read', 'http'], 'hosts' => ['api.example.com']];
    file_put_contents($hello.'/teme-plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    PluginFixtures::installed(['tests/hello' => $hello], references: ['tests/hello' => 'bbb222']);

    PluginFixtures::serving('admin', fn () => app(PluginLoader::class)->load());
    $row = array_values(array_filter(app(PluginList::class)->all(), fn (PluginRow $row): bool => $row->name === 'tests/hello'))[0] ?? null;

    expect(app(LoadedPlugins::class)->running())->toBe([])
        ->and($row?->state)->toBe(PluginState::Waiting);
});

it('keeps its cache across Teme versions, and still checks the Teme version on every read', function (): void {
    $hello = PluginFixtures::copy('hello');
    PluginFixtures::installed(['tests/hello' => $hello], references: ['tests/hello' => 'aaa111']);
    config(['teme.plugin_cache' => $this->cache, 'teme.version' => '0.5.0']);
    Artisan::call('teme:plugins:cache');

    // Built under one version (as the image builds it, before TEME_VERSION is
    // set), read under another: the cache is used, and the plugin's code is
    // not opened again.
    unlink($hello.'/src/Hello.php');
    config(['teme.version' => '0.5.3']);
    app(PluginRegistry::class)->flush();

    expect(app(PluginRegistry::class)->find('tests/hello')?->problems)->toBe([]);

    // A Teme the plugin's range leaves out is still refused, from the cached
    // manifest: the version is a live check, not part of the cache.
    config(['teme.version' => '0.6.0']);
    app(PluginRegistry::class)->flush();

    expect(app(PluginRegistry::class)->find('tests/hello')?->usable())->toBeFalse();
});

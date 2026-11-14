<?php

declare(strict_types=1);

use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Plugins\PluginFixtures;

beforeEach(function (): void {
    PluginFixtures::use();
    $this->cache = storage_path('framework/testing/teme-plugins-'.bin2hex(random_bytes(6)).'.php');
});

afterEach(function (): void {
    @unlink($this->cache);
    PluginFixtures::forget();
});

it('finds the configured folders, each once, by name', function (): void {
    PluginFixtures::use('hello', 'reaching', 'hello');

    $found = app(PluginRegistry::class)->all();

    expect(array_keys($found))->toBe(['tests/hello', 'tests/reaching'])
        ->and($found['tests/hello'])->toBeInstanceOf(FoundPlugin::class)
        ->and($found['tests/hello']->code)->toBe('hello')
        ->and($found['tests/hello']->path)->toBe(realpath(PluginFixtures::path('hello')))
        ->and($found['tests/hello']->usable())->toBeTrue()
        ->and($found['tests/reaching']->usable())->toBeFalse();
});

it('finds Composer’s teme-plugin packages', function (): void {
    PluginFixtures::installed(['tests/hello' => 'hello']);

    $hello = app(PluginRegistry::class)->find('tests/hello');

    expect($hello?->path)->toBe(realpath(PluginFixtures::path('hello')))
        ->and($hello?->problems)->toBe([])
        ->and(array_keys(app(PluginRegistry::class)->all()))->toBe(['tests/hello']);
});

it('lists a second plugin with a code already used under its path, as can’t be used', function (): void {
    $second = PluginFixtures::copy('hello', ['name' => 'tests/hello-two'], ['name' => 'tests/hello-two']);
    PluginFixtures::use('hello', $second);

    $found = app(PluginRegistry::class)->all();
    $listed = $found[realpath($second)] ?? null;

    expect(array_keys($found))->toBe(['tests/hello', realpath($second)])
        ->and($listed?->name)->toBe('tests/hello-two')
        ->and($listed?->problems)->toBe(['The plugin code "hello" is already used by tests/hello.'])
        ->and($listed?->usable())->toBeFalse();
});

it('writes the cache with each plugin’s code problems, and reads it when it is there', function (): void {
    $reaching = PluginFixtures::copy('reaching');
    PluginFixtures::installed(['tests/reaching' => $reaching]);
    config(['teme.plugin_cache' => $this->cache]);
    $sentence = 'It uses Teme’s own code, which plugins may not use: App\\Domain\\Contents\\Models\\Content in src/Listens.php, line 7. (and 2 more)';

    Artisan::call('teme:plugins:cache');
    $output = Artisan::output();
    $cached = require $this->cache;

    expect($output)->toContain('Plugins cached: 1.')
        ->and($cached['plugins'][0]['package'])->toBe('tests/reaching')
        ->and($cached['plugins'][0]['code'])->toBe([$sentence]);

    // Gone from the code now: only the cache can still say it, so nothing was scanned.
    unlink($reaching.'/src/Listens.php');
    app(PluginRegistry::class)->flush();

    expect(app(PluginRegistry::class)->find('tests/reaching')?->problems)->toBe([$sentence]);

    Artisan::call('teme:plugins:clear');

    expect(is_file($this->cache))->toBeFalse()
        ->and(app(PluginRegistry::class)->find('tests/reaching')?->problems)->toBe([])
        ->and(is_file($this->cache))->toBeFalse();
});

it('checks requires.teme and the hooks live even when the cache says the plugin is fine', function (): void {
    PluginFixtures::installed(['tests/hello' => 'hello']);
    config(['teme.plugin_cache' => $this->cache]);
    Artisan::call('teme:plugins:cache');

    $cached = require $this->cache;
    $cached['plugins'][0]['manifest']['requires'] = ['teme' => '^0.4'];
    $cached['plugins'][0]['manifest']['hooks'] = ['ContentPublished', 'PostSaved'];
    file_put_contents($this->cache, '<?php return '.var_export($cached, true).';'.PHP_EOL);
    app(PluginRegistry::class)->flush();

    expect(app(PluginRegistry::class)->find('tests/hello')?->problems)->toBe([
        'The plugin requires Teme ^0.4, but this is Teme 0.5.0.',
        'PostSaved isn’t a Teme hook.',
    ]);
});

it('says no plugins are installed, and reads nothing, when there are none', function (): void {
    PluginFixtures::installed([]);
    DB::enableQueryLog();

    expect(app(PluginRegistry::class)->all())->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

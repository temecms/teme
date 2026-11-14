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
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\RecordingSubscriptions;
use Tests\Fixtures\Plugins\ReportedFailures;

/*
 * A plugin, or the cache of them, that can't be read must never lock anyone
 * out: not the safe pages, not an ordinary page, not the console that
 * disables a plugin (I-3353).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->subscriptions = new RecordingSubscriptions;
    app()->instance(HookSubscriptions::class, $this->subscriptions);
    $this->failures = new ReportedFailures(app());
    app()->instance(ExceptionHandler::class, $this->failures);

    $this->enable = fn (string $folder, array $changes = []): PluginRecord => PluginRecord::factory()->enabled()->create([
        'name' => 'tests/'.$folder,
        'code' => PluginFixtures::manifest($folder)['code'],
        'version' => PluginFixtures::manifest($folder)['version'],
        'consent' => [
            'capabilities' => PluginFixtures::manifest($folder)['capabilities'] ?? [],
            'hosts' => PluginFixtures::manifest($folder)['hosts'] ?? [],
            'hooks' => PluginFixtures::manifest($folder)['hooks'] ?? [],
        ],
        ...$changes,
    ]);
});

afterEach(fn () => PluginFixtures::forget());

it('goes without a plugin cache cut short, on a safe page, an ordinary page and in the console', function (): void {
    $this->seed(RoleSeeder::class);
    $hello = PluginFixtures::copy('hello');
    PluginFixtures::use();
    PluginFixtures::installed(['tests/hello' => $hello]);
    [$cache] = PluginFixtures::markingCache();
    file_put_contents($cache, "<?php return ['tests/hello' => ['name' => 'tests/hello', 'path' => ");
    config(['teme.plugin_cache' => $cache]);
    ($this->enable)('hello');

    PluginFixtures::serving('admin/login', fn () => app(PluginLoader::class)->load());

    expect(app(LoadedPlugins::class)->running())->toBe([]);

    PluginFixtures::serving('admin', fn () => app(PluginLoader::class)->load());

    expect(array_keys(app(LoadedPlugins::class)->running()))->toBe(['tests/hello']);

    $this->actingAs(User::factory()->create()->assignRole('administrator'))->get('/admin')->assertOk();

    $exit = Artisan::call('teme:plugins:disable', ['name' => 'tests/hello']);

    expect($exit)->toBe(0)
        ->and(PluginRecord::query()->sole()->state)->toBe('disabled');
});

it('takes a plugin cache that holds something else as no cache', function (string $contents): void {
    $hello = PluginFixtures::copy('hello');
    PluginFixtures::use();
    PluginFixtures::installed(['tests/hello' => $hello]);
    [$cache] = PluginFixtures::markingCache();
    file_put_contents($cache, $contents);
    config(['teme.plugin_cache' => $cache]);

    expect(array_keys(app(PluginRegistry::class)->all()))->toBe(['tests/hello'])
        ->and(app(PluginRegistry::class)->find('tests/hello')?->usable())->toBeTrue();
})->with([
    'a number' => ["<?php return 5;\n"],
    'a list of strings' => ["<?php return ['tests/hello' => 'x'];\n"],
    'a plugin missing its keys' => ["<?php return ['tests/hello' => ['name' => 'tests/hello']];\n"],
    'code that throws' => ["<?php throw new RuntimeException('no');\n"],
]);

it('lists the other plugins when one can’t be read, and says why that one can’t be used', function (): void {
    $broken = PluginFixtures::copy('widened');

    if (! PluginFixtures::unreadable($broken.'/teme-plugin.json')) {
        $this->markTestSkipped('This machine reads every file, even with no permission.');
    }

    PluginFixtures::use('hello', $broken);
    ($this->enable)('hello');

    $rows = app(PluginList::class)->all();
    $unreadable = array_values(array_filter($rows, fn (PluginRow $row): bool => $row->name !== 'tests/hello'))[0] ?? null;

    PluginFixtures::serving('admin', fn () => app(PluginLoader::class)->load());

    expect(array_map(fn (PluginRow $row): string => $row->name, $rows))->toContain('tests/hello')
        ->and($rows)->toHaveCount(2)
        ->and($unreadable?->state)->toBe(PluginState::CantBeUsed)
        ->and($unreadable?->reason)->toBe('Teme couldn’t read the plugin’s files (teme-plugin.json): they can’t be opened.')
        ->and(array_keys(app(LoadedPlugins::class)->running()))->toBe(['tests/hello']);

    // The raw failure, with the server's paths, goes to the log alone, with the plugin it came from.
    $logged = array_values(array_filter($this->failures->reported, fn (array $report): bool => ($report[1]['plugin'] ?? null) === realpath($broken)));

    expect($logged)->not->toBe([])
        ->and($unreadable?->reason)->not->toContain($broken);
});

it('says plainly when a plugin’s manifest is too large to read, and logs the rest', function (): void {
    $copy = PluginFixtures::copy('hello');
    file_put_contents($copy.'/teme-plugin.json', json_encode([...PluginFixtures::manifest('hello'), 'description' => str_repeat('x', 1_100_000)], JSON_THROW_ON_ERROR));
    PluginFixtures::use($copy);

    expect(app(PluginRegistry::class)->all()[realpath($copy)]->problems ?? null)->toBe(['Teme couldn’t read the plugin’s files (teme-plugin.json): they’re too large.'])
        ->and($this->failures->reported)->toHaveCount(1)
        ->and($this->failures->reported[0][1]['plugin'] ?? null)->toBe(realpath($copy));
});

it('opens no plugin file when the server disables every plugin, or on a safe page, or with nothing enabled', function (string $case): void {
    [$cache, $marker] = PluginFixtures::markingCache();
    PluginFixtures::use('hello');
    config(['teme.plugin_cache' => $cache]);

    if ($case !== 'nothing enabled') {
        ($this->enable)('hello');
    }

    if ($case === 'disabled on the server') {
        config(['teme.plugins.disabled' => '*']);
    }

    PluginFixtures::serving($case === 'a safe page' ? 'admin/account/security' : 'admin', fn () => app(PluginLoader::class)->load());

    expect(is_file($marker))->toBeFalse()
        ->and($this->subscriptions->calls)->toBe([])
        ->and(app(LoadedPlugins::class)->running())->toBe([]);
})->with(['disabled on the server', 'a safe page', 'nothing enabled']);

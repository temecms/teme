<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Plugins\Faults\SpooledFaults;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\Queries\PluginRow;
use App\Domain\Plugins\Seams\DispatcherSubscriptions;
use App\Domain\Plugins\Seams\HookSubscriptions;
use App\Domain\Plugins\Seams\PluginContributions;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Hooks\HookRefused;
use Teme\Contracts\Media\MediaStored;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Plugins\Hello\Hello;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\RecordingContributions;
use Tests\Fixtures\Plugins\RecordingSubscriptions;
use Tests\Fixtures\Plugins\ReportedFailures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->subscriptions = new RecordingSubscriptions;
    app()->instance(HookSubscriptions::class, $this->subscriptions);

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

it('starts an enabled plugin with its own hooks, its capabilities, its declared hooks and its stopped parts', function (): void {
    PluginFixtures::use('hello');
    ($this->enable)('hello', ['quarantine' => [
        'tests/hello#ContentPublished#2' => ['version' => '1.0.0', 'error' => 'RuntimeException: no', 'at' => 1],
        'tests/hello#ContentPublished#3' => ['version' => '0.9.0', 'error' => 'RuntimeException: no', 'at' => 1],
    ]]);

    app(PluginLoader::class)->load();

    expect($this->subscriptions->calls)->toBe([
        ['for', ['tests/hello', ['content.read'], ['ContentPublished'], ['tests/hello#ContentPublished#2']]],
        ['close', 'tests/hello'],
    ])
        ->and($this->subscriptions->subscribed)->toBe(['tests/hello' => ['tests/hello#ContentPublished#1']])
        ->and(array_keys(app(LoadedPlugins::class)->running()))->toBe(['tests/hello']);
});

it('builds a plugin with only what Teme offers plugins, never through the container', function (): void {
    PluginFixtures::use('hello');
    ($this->enable)('hello');
    $never = Mockery::mock(SettingsReader::class);
    app()->bind(Hello::class, fn (): Hello => new Hello($never));

    app(PluginLoader::class)->load();
    $hello = app(LoadedPlugins::class)->running()['tests/hello'] ?? null;

    expect($hello)->toBeInstanceOf(Hello::class)
        ->and($hello?->settings)->not->toBe($never)
        ->and($hello?->settings)->toBe(app(SettingsReader::class));
});

it('skips a plugin that fails to start, removes what it subscribed, records a fault and starts the next', function (): void {
    PluginFixtures::use('broken-start', 'hello');
    ($this->enable)('broken-start');
    ($this->enable)('hello');
    $handler = new ReportedFailures(app());
    $this->app->instance(ExceptionHandler::class, $handler);

    app(PluginLoader::class)->load();
    $loaded = app(LoadedPlugins::class);

    expect($this->subscriptions->calls('forget'))->toBe(['tests/broken-start'])
        ->and($this->subscriptions->calls('close'))->toBe(['tests/broken-start', 'tests/hello'])
        ->and($this->subscriptions->subscribed)->toBe(['tests/hello' => ['tests/hello#ContentPublished#1']])
        ->and(array_map(fn (array $report): array => [$report[0]->getMessage(), $report[1]], $handler->reported))->toBe([['The broken plugin broke.', ['plugin' => 'tests/broken-start', 'subscription' => 'tests/broken-start#boot', 'hook' => '']]])
        ->and(array_keys($loaded->running()))->toBe(['tests/hello'])
        ->and($loaded->state('tests/broken-start'))->toBe(PluginState::CantBeUsed)
        ->and($loaded->failure('tests/broken-start'))->toBe('The broken plugin broke.');
});

it('reports a plugin’s failed start to the log, writing nothing to the database and no file', function (): void {
    $handler = new ReportedFailures(app());
    $this->app->instance(ExceptionHandler::class, $handler);
    $folder = storage_path('framework/cache');
    $before = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)));
    $failure = new RuntimeException('The broken plugin broke.');

    DB::enableQueryLog();
    app(SpooledFaults::class)->record('tests/broken-start', 'tests/broken-start#boot', '', $failure);

    expect($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0][0])->toBe($failure)
        ->and($handler->reported[0][1])->toBe(['plugin' => 'tests/broken-start', 'subscription' => 'tests/broken-start#boot', 'hook' => ''])
        ->and(DB::getQueryLog())->toBe([])
        ->and(array_keys(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)))))->toBe(array_keys($before));
});

it('starts no plugin the server disables, by name or all', function (string $disabled, array $running, ?PluginState $state): void {
    PluginFixtures::use('hello', 'widened');
    ($this->enable)('hello');
    ($this->enable)('widened');
    config(['teme.plugins.disabled' => $disabled]);

    app(PluginLoader::class)->load();
    $loaded = app(LoadedPlugins::class);

    expect(array_keys($loaded->running()))->toBe($running)
        ->and($loaded->state('tests/hello'))->toBe($state);
})->with([
    'by name' => [' tests/hello , tests/nobody', ['tests/widened'], PluginState::DisabledOnServer],
    // Every plugin: none is even looked for.
    'all' => ['*', [], null],
]);

it('starts no plugin whose code is gone, whose code names App, or whose Teme version doesn’t fit', function (): void {
    $reaching = PluginFixtures::copy('hello', ['name' => 'tests/reaching-copy', 'code' => 'reaching-copy'], ['name' => 'tests/reaching-copy'], ['src/Reach.php' => "<?php\n\nnamespace Tests\\Fixtures\\Plugins\\Hello;\n\nuse App\\Models\\User;\n"]);
    $old = PluginFixtures::copy('widened', ['name' => 'tests/old', 'code' => 'old', 'requires' => ['teme' => '^0.4']], ['name' => 'tests/old']);
    PluginFixtures::use($reaching, $old);
    ($this->enable)('hello', ['name' => 'tests/gone', 'code' => 'gone']);
    ($this->enable)('hello', ['name' => 'tests/reaching-copy', 'code' => 'reaching-copy']);
    ($this->enable)('widened', ['name' => 'tests/old', 'code' => 'old']);

    app(PluginLoader::class)->load();
    $loaded = app(LoadedPlugins::class);

    expect($loaded->running())->toBe([])
        ->and($this->subscriptions->calls)->toBe([])
        ->and($loaded->state('tests/gone'))->toBe(PluginState::CodeGone)
        ->and($loaded->state('tests/reaching-copy'))->toBe(PluginState::CantBeUsed)
        ->and($loaded->state('tests/old'))->toBe(PluginState::CantBeUsed);
});

it('starts an update that asks for nothing more, and holds one that asks for more until someone agrees', function (array $update, ?PluginState $state): void {
    $copy = PluginFixtures::copy('widened', $update);
    PluginFixtures::use($copy);
    ($this->enable)('widened', ['consent' => ['capabilities' => ['content.read', 'http'], 'hosts' => ['api.example.com'], 'hooks' => ['ContentPublished']]]);

    app(PluginLoader::class)->load();
    $loaded = app(LoadedPlugins::class);

    expect($loaded->state('tests/widened'))->toBe($state ?? PluginState::Enabled)
        ->and(array_keys($loaded->running()))->toBe($state === null ? ['tests/widened'] : [])
        ->and(PluginRecord::query()->sole()->version)->toBe('1.0.0');
})->with([
    'a new capability' => [['capabilities' => ['content.read', 'http', 'media.read']], PluginState::Waiting],
    'a wildcard over the agreed host' => [['hosts' => ['*.example.com']], PluginState::Waiting],
    'a new version asking nothing more' => [['version' => '1.1.0'], null],
]);

it('starts nothing, and reads nothing, when no plugin is installed', function (): void {
    PluginFixtures::use();
    ($this->enable)('hello');

    DB::enableQueryLog();
    app(PluginLoader::class)->load();

    expect(DB::getQueryLog())->toBe([])
        ->and($this->subscriptions->calls)->toBe([])
        ->and(app(LoadedPlugins::class)->running())->toBe([]);
});

it('registers what a plugin contributes only after its register() succeeded', function (): void {
    PluginFixtures::use('broken-start', 'hello', 'widened');
    ($this->enable)('broken-start');
    ($this->enable)('hello');
    ($this->enable)('widened');
    $contributions = new RecordingContributions(['tests/widened' => 'Its settings can’t be used: no.']);
    app()->instance(PluginContributions::class, $contributions);

    app(PluginLoader::class)->load();
    $loaded = app(LoadedPlugins::class);

    expect($contributions->added)->toBe(['tests/hello'])
        ->and($this->subscriptions->calls('forget'))->toBe(['tests/broken-start', 'tests/widened'])
        ->and($loaded->failure('tests/widened'))->toBe('Its settings can’t be used: no.')
        ->and(array_keys($loaded->running()))->toBe(['tests/hello']);
});

it('runs none of a plugin’s code until it is enabled and the next request starts it (I-3353)', function (): void {
    // Loud's own marker(), but asking the class would load it.
    $marker = sys_get_temp_dir().'/teme-loud-plugin-'.getmypid();
    @unlink($marker);
    PluginFixtures::use('loud');
    app()->instance(Elevation::class, new OperatorElevation);

    $found = app(PluginRegistry::class)->all();
    $fresh = app(PluginRegistry::class)->fresh($found['tests/loud']);
    $listed = app(PluginList::class)->all();
    app(PluginLoader::class)->load();

    expect($fresh->problems)->toBe([])
        ->and(array_map(fn (PluginRow $row): string => $row->name, $listed))->toBe(['tests/loud'])
        ->and(file_exists($marker))->toBeFalse();

    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/loud', '1.0.0', [], [], [], null));

    expect(file_exists($marker))->toBeFalse();

    app(PluginLoader::class)->load();

    expect(file_exists($marker))->toBeTrue()
        ->and(array_keys(app(LoadedPlugins::class)->running()))->toBe(['tests/loud']);

    @unlink($marker);
});

it('keeps a plugin’s subscriptions through the dispatcher, and refuses what the dispatcher refuses, in its words', function (): void {
    $subscriptions = app(DispatcherSubscriptions::class);
    $dispatcher = app(HookDispatcher::class);
    $hooks = $subscriptions->for('tests/hello', ['content.read'], ['ContentPublished', 'MediaStored'], []);
    $listener = function (): void {};

    $first = $hooks->on(ContentPublished::class, $listener);
    $second = $hooks->on(ContentPublished::class, $listener);
    $hooks->off($first);

    expect($second->id)->toBe('tests/hello#ContentPublished#2')
        ->and($dispatcher->wants(ContentPublished::class))->toBeTrue()
        ->and(fn () => $hooks->on(stdClass::class, $listener))->toThrow(HookRefused::class, 'It listens for stdClass, which isn’t a Teme hook.')
        ->and(fn () => $hooks->on(ContentTrashed::class, $listener))->toThrow(HookRefused::class, 'It listens for ContentTrashed, which its teme-plugin.json doesn’t list under hooks.')
        ->and(fn () => $hooks->on(MediaStored::class, $listener))->toThrow(HookRefused::class, 'It listens for MediaStored without asking for media.read in its teme-plugin.json.')
        ->and($dispatcher->wants(MediaStored::class))->toBeFalse();

    $subscriptions->close($hooks);
    $hooks->off($second);

    expect(fn () => $hooks->on(ContentPublished::class, $listener))->toThrow(HookRefused::class, 'It listens for ContentPublished after it finished starting: subscribe in register().')
        ->and($dispatcher->wants(ContentPublished::class))->toBeTrue();

    $subscriptions->forget('tests/hello');

    // Core's search feed always listens for publishes, so the hook stays wanted; the plugin's own is gone.
    expect($dispatcher->wants(ContentPublished::class))->toBeTrue();
});

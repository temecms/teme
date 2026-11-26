<?php

declare(strict_types=1);

use App\Domain\Export\LeftOut;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Exports\DeclaredExports;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\Plugins\Exporter\Exporter;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once dirname(__DIR__).'/Plugins/Seo/Seokit.php';

foreach (glob(dirname(__DIR__, 2).'/Fixtures/plugins/exporter/src/*.php') ?: [] as $file) {
    require_once $file;
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->folder = ExportAs::freshFolder();
    Exporter::reset();

    $this->enable = function (): array {
        // The helper enables the SEO kit; this plugin is enabled the same way.
        app()->instance(Elevation::class, new OperatorElevation);
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        $faults = new SeoSocketFaults;
        app()->instance(ListenerFaults::class, $faults);
        PluginFixtures::use('exporter');
        $manifest = app(PluginRegistry::class)->find('tests/exporter')?->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/exporter', $manifest?->version() ?? '1.0.0', [], [], [], null));
        app(PluginLoader::class)->load();

        return ['faults' => $faults];
    };
});

afterEach(function (): void {
    Exporter::reset();
    ExportAs::remove($this->folder);
    PluginFixtures::forget();
});

it('writes an enabled plugin’s lines, a page at a time, under its own file name', function (): void {
    ($this->enable)();

    ExportAs::operator($this->folder);
    $manifest = ExportAs::manifest($this->folder);
    $file = collect($manifest['files'])->firstWhere('kind', 'plugin_exporter_bookings');

    expect(ExportAs::lines($this->folder, 'plugin_exporter_bookings'))->toBe([
        ['kind' => 'plugin_exporter_bookings', 'version' => 1, 'id' => 1, 'guest' => 'Ada'],
        ['kind' => 'plugin_exporter_bookings', 'version' => 1, 'id' => 2, 'guest' => 'Grace'],
    ])
        ->and($file)->toMatchArray(['file' => 'plugin_exporter_bookings.ndjson', 'lines' => 2])
        ->and($manifest['plugin_kinds'])->toBe([[
            'kind' => 'plugin_exporter_bookings',
            'plugin' => 'tests/exporter',
            'description' => 'Exporter’s bookings, as the plugin hands them over.',
            'fields' => ['id' => 'The booking\'s number.', 'guest' => 'Who booked.'],
        ]]);
});

it('writes nothing of a plugin that is disabled', function (): void {
    ($this->enable)();
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/exporter', null));

    ExportAs::operator($this->folder);
    $manifest = ExportAs::manifest($this->folder);

    expect(file_exists("{$this->folder}/plugin_exporter_bookings.ndjson"))->toBeFalse()
        ->and(array_column($manifest['files'], 'kind'))->not->toContain('plugin_exporter_bookings')
        ->and($manifest['plugin_kinds'])->toBe([]);
});

it('leaves out a plugin that throws, says so, and finishes the export', function (): void {
    ['faults' => $faults] = ($this->enable)();
    Exporter::$lines = function (?string $after): array {
        if ($after === null) {
            return ['lines' => [['id' => 1, 'guest' => 'Ada']], 'next' => '1'];
        }

        throw new RuntimeException('The bookings are out of reach.');
    };

    ExportAs::operator($this->folder);
    $manifest = ExportAs::manifest($this->folder);

    expect(file_exists("{$this->folder}/plugin_exporter_bookings.ndjson"))->toBeFalse()
        ->and(file_exists("{$this->folder}/people.ndjson"))->toBeTrue()
        ->and(array_column($manifest['files'], 'kind'))->not->toContain('plugin_exporter_bookings')
        ->and($manifest['plugin_kinds'])->toBe([])
        ->and(collect($manifest['left_out'])->last()['what'])->toBe('Exporter’s bookings (plugin_exporter_bookings.ndjson)')
        ->and(json_encode($manifest))->not->toContain('out of reach')
        ->and($faults->seen)->toHaveCount(1)
        ->and($faults->seen[0][0])->toBe('tests/exporter')
        ->and(ExportAs::leftovers($this->folder))->toBe([]);
});

it('leaves out a plugin whose exports can’t be read at all', function (): void {
    ($this->enable)();
    Exporter::$throws = true;

    ExportAs::operator($this->folder);

    expect(file_exists("{$this->folder}/plugin_exporter_bookings.ndjson"))->toBeFalse()
        ->and(collect(ExportAs::manifest($this->folder)['left_out'])->last()['what'])->toContain('plugin_exporter_bookings.ndjson');
});

it('refuses a page that sets a line’s own kind', function (): void {
    ($this->enable)();
    Exporter::$lines = fn (?string $after): array => ['lines' => [['kind' => 'other']], 'next' => null];

    ExportAs::operator($this->folder);

    expect(file_exists("{$this->folder}/plugin_exporter_bookings.ndjson"))->toBeFalse()
        ->and(ExportAs::manifest($this->folder)['left_out'])->toHaveCount(count(LeftOut::ALL) + 1);
});

it('refuses an export declared without an id or a noun, or twice', function (): void {
    expect(DeclaredExports::errors(['exports' => [['id' => 'Bad id', 'noun' => 'booking']]]))->toHaveCount(2)
        ->and(DeclaredExports::errors(['exports' => [['id' => 'bookings', 'noun' => 'booking|bookings'], ['id' => 'bookings', 'noun' => 'booking|bookings']]]))->toHaveCount(1)
        ->and(DeclaredExports::errors(['exports' => [['id' => 'bookings', 'noun' => 'booking|bookings']]]))->toBe([]);
});

it('refuses a plugin that declares exports it doesn’t hand over', function (): void {
    expect(DeclaredExports::startErrors(['exports' => [['id' => 'bookings', 'noun' => 'booking|bookings']]], new stdClass))
        ->toHaveCount(1)
        ->and(DeclaredExports::startErrors([], new Exporter))->toBe(['It hands over exports but declares none.']);
});

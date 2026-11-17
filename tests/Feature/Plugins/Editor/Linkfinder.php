<?php

declare(strict_types=1);

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Tests\Fixtures\Plugins\Linkfinder\Linkfinder;
use Tests\Fixtures\Plugins\PluginFixtures;

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/linkfinder/src/*.php') ?: [] as $file) {
    require_once $file;
}

/**
 * The faults recorded while a test runs, kept in order.
 */
final class EditorPanelFaults implements ListenerFaults
{
    /** @var list<array{string, string, string, Throwable}> */
    public array $seen = [];

    public function record(string $owner, string $id, string $hook, Throwable $failure): void
    {
        $this->seen[] = [$owner, $id, $hook, $failure];
    }
}

/**
 * A clock that moves only when a test says how far each reading moves it.
 */
final class EditorPanelClock implements ColumnClock
{
    public int $now = 0;

    /** @var list<int> */
    public array $steps = [];

    public function nanoseconds(): int
    {
        if ($this->steps !== []) {
            $this->now += array_shift($this->steps);
        }

        return $this->now;
    }
}

/**
 * Enables Link Finder, agreeing to what it asks unless told otherwise, and
 * loads it, with the faults and the clock a test can read and move.
 *
 * @param  list<string>|null  $capabilities
 *
 * @return array{faults: EditorPanelFaults, clock: EditorPanelClock}
 */
function linkfinderEnabled(?array $capabilities = null): array
{
    Linkfinder::reset();
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $faults = new EditorPanelFaults;
    $clock = new EditorPanelClock;
    app()->instance(ListenerFaults::class, $faults);
    app()->instance(ColumnClock::class, $clock);

    PluginFixtures::use('linkfinder');
    $manifest = app(PluginRegistry::class)->find('tests/linkfinder')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/linkfinder', $manifest?->version() ?? '1.0.0', $capabilities ?? $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);

    return ['faults' => $faults, 'clock' => $clock];
}

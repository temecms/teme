<?php

/**
 * Finds the enabled plugins that declare an area (docs/plans/plugin-sockets-2.md,
 * A2), read from their records and manifests each time, and the data
 * behind each while it runs in this process. Holds nothing itself.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\SettingsRegistry;
use Teme\Contracts\Admin\HasPluginArea;
use Teme\Contracts\Admin\PluginArea;
use Throwable;

final readonly class PluginAreas
{
    public function __construct(
        private PluginRecords $records,
        private PluginRegistry $registry,
        private LoadedPlugins $loaded,
        private SettingsRegistry $settings,
    ) {}

    /**
     * Every enabled plugin with an area, in the order they were enabled.
     *
     * @return list<AreaPlugin>
     */
    public function all(): array
    {
        $records = $this->records->enabled();
        usort($records, fn ($a, $b): int => [$a->enabled_at?->getTimestamp() ?? 0, $a->name] <=> [$b->enabled_at?->getTimestamp() ?? 0, $b->name]);
        $found = [];

        foreach ($records as $record) {
            $plugin = $this->read($record->name, $record->code);

            if ($plugin !== null) {
                $found[] = $plugin;
            }
        }

        return $found;
    }

    /**
     * The enabled plugin with this code and an area, or null.
     */
    public function find(string $code): ?AreaPlugin
    {
        foreach ($this->records->enabled() as $record) {
            if ($record->code === $code) {
                return $this->read($record->name, $record->code);
            }
        }

        return null;
    }

    /**
     * Whether this plugin has its own area and a settings group, so its
     * settings belong as that area's Settings tab rather than under
     * Settings › From plugins.
     */
    public function hasSettingsTab(string $code): bool
    {
        return $this->find($code) !== null && $this->settings->find("plugin.{$code}") !== null;
    }

    private function read(string $name, string $code): ?AreaPlugin
    {
        $found = $this->registry->find($name);
        $manifest = $found instanceof FoundPlugin ? $found->manifest : null;
        $area = $manifest?->contributes()['area'] ?? null;

        if ($manifest === null || ! is_array($area)) {
            return null;
        }

        $running = $this->loaded->running()[$name] ?? null;
        $data = null;

        if ($running instanceof HasPluginArea) {
            try {
                $data = $running->pluginArea();
            } catch (Throwable) {
                // A plugin that can't hand over its area shows each block's failure line.
                $data = null;
            }
        }

        return new AreaPlugin($name, $code, AreaWords::text($code, $manifest->label()), DeclaredArea::from($area), $data instanceof PluginArea ? $data : null);
    }
}

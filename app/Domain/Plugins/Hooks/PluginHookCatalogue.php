<?php

/**
 * The hooks the enabled plugins declare for other plugins
 * (docs/plans/plugin-sockets-3.md), each under its full name
 * `{code}:{name}`: how a listener's class is named, and what the reference
 * lists under each plugin. It reads manifests and rows, never a plugin's code.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Hooks;

use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;

final readonly class PluginHookCatalogue
{
    public function __construct(
        private PluginRegistry $registry,
        private PluginRecords $records,
    ) {}

    /**
     * Returns every hook an enabled plugin declares, in the plugins' name
     * order, then the order each declares them.
     *
     * @return list<array{plugin: string, label: string, code: string, name: string, fullName: string, class: string, description: string}>
     */
    public function all(): array
    {
        // With no plugin installed, no row is read, as the loader does.
        if (! $this->registry->anyInstalled()) {
            return [];
        }

        $hooks = [];

        foreach ($this->records->enabled() as $record) {
            $manifest = $this->registry->find($record->name)?->manifest;

            if ($manifest === null) {
                continue;
            }

            foreach (DeclaredHooks::of($manifest) as $hook) {
                $hooks[] = [
                    'plugin' => $record->name,
                    'label' => $manifest->label(),
                    'code' => $manifest->code(),
                    'name' => $hook['name'],
                    'fullName' => $manifest->code().':'.$hook['name'],
                    'class' => $hook['class'],
                    'description' => $hook['description'],
                ];
            }
        }

        return $hooks;
    }

    /**
     * Returns the full name an enabled plugin declares this class under, or
     * null when none declares it.
     */
    public function fullName(string $class): ?string
    {
        $class = strtolower(ltrim($class, '\\'));

        foreach ($this->all() as $hook) {
            if (strtolower($hook['class']) === $class) {
                return $hook['fullName'];
            }
        }

        return null;
    }
}

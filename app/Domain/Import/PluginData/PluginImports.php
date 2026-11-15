<?php

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Seams\PluginServices;
use Teme\Contracts\Plugins\Capability;

/**
 * The plugins an import hands WordPress data to (I-349): those enabled and
 * running in this process, granted import.wordpress, that declare what they
 * bring, in the order of their codes. A disabled plugin, or one never
 * granted the capability, gets nothing and has nothing read for it.
 */
final readonly class PluginImports
{
    public function __construct(
        private PluginRecords $records,
        private PluginRegistry $registry,
        private LoadedPlugins $loaded,
        private PluginServices $services,
    ) {}

    /**
     * Returns each plugin the import hands data to, by code.
     *
     * @return list<PluginImport>
     */
    public function all(): array
    {
        $records = $this->records->enabled();

        if ($records === []) {
            return [];
        }

        $found = $this->registry->all();
        $imports = [];

        foreach ($records as $record) {
            $plugin = $found[$record->name] ?? null;
            $granted = $record->consent['capabilities'] ?? [];

            if ($plugin?->manifest === null || $this->loaded->state($record->name) !== PluginState::Enabled || ! in_array(Capability::ImportWordPress->value, $granted, true)) {
                continue;
            }

            $declared = $plugin->manifest->imports();

            if ($declared['options'] === [] && $declared['postTypes'] === [] && $declared['userMeta'] === [] && $declared['termMeta'] === []) {
                continue;
            }

            $imports[$plugin->code] = new PluginImport($plugin->name, $plugin->code, $plugin->manifest->label(), $declared, $granted, Consent::hosts($record->consent, $plugin->manifest), $this->services);
        }

        ksort($imports, SORT_STRING);

        return array_values($imports);
    }

    /**
     * Returns the plugin with this code the import hands data to, or null.
     */
    public function find(string $code): ?PluginImport
    {
        foreach ($this->all() as $import) {
            if ($import->code === $code) {
                return $import;
            }
        }

        return null;
    }
}

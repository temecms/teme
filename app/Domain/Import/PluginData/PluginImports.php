<?php

/**
 * The plugins an import hands WordPress data to (I-349): those enabled and
 * running in this process, granted import.wordpress, that declare what they
 * bring, in the order of their codes. A disabled plugin, or one never
 * granted the capability, gets nothing and has nothing read for it.
 * Bound scoped: find() remembers each plugin, and so its importer, by code
 * for the life of one job or request.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Seams\PluginServices;
use Teme\Contracts\Plugins\Capability;

final class PluginImports
{
    /** @var array<string, ?PluginImport> what find() answered, by code */
    private array $found = [];

    public function __construct(
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
        private readonly LoadedPlugins $loaded,
        private readonly PluginServices $services,
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

            // Only the names both declared now and agreed to (I-349).
            $declared = ['importer' => $plugin->manifest->imports()['importer'], ...Consent::imports($record->consent, $plugin->manifest)];

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
        if (array_key_exists($code, $this->found)) {
            return $this->found[$code];
        }

        $this->found[$code] = null;

        foreach ($this->all() as $import) {
            if ($import->code === $code) {
                $this->found[$code] = $import;
            }
        }

        return $this->found[$code];
    }
}

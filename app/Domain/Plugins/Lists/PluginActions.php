<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Lists;

use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use Teme\Contracts\Plugins\Capability;

/**
 * The actions a plugin declares for its settings page
 * (docs/proposals/plugin-sockets-1.md): buttons that queue one of its jobs,
 * offered only while it is enabled and granted jobs.
 */
final readonly class PluginActions
{
    public function __construct(
        private PluginRecords $records,
        private PluginRegistry $registry,
    ) {}

    /**
     * Returns the actions of the plugin whose settings group this is, each
     * with its id, label and description; none for any other group, or a
     * plugin not enabled or not granted jobs.
     *
     * @return list<array{id: string, label: string, description: string|null}>
     */
    public function forGroup(string $group): array
    {
        if (! str_starts_with($group, 'plugin.')) {
            return [];
        }

        $record = $this->enabled(substr($group, strlen('plugin.')));

        if ($record === null) {
            return [];
        }

        $actions = [];

        foreach ($this->declared($record) as $action) {
            $actions[] = ['id' => $action['id'], 'label' => $action['label'], 'description' => $action['description']];
        }

        return $actions;
    }

    /**
     * One declared action of an enabled plugin granted jobs, by the plugin's
     * code and the action's id; null when there is none.
     *
     * @return array{id: string, label: string, description: string|null, job: string, name: string}|null
     */
    public function find(string $code, string $id): ?array
    {
        $record = $this->enabled($code);

        foreach ($record === null ? [] : $this->declared($record) as $action) {
            if ($action['id'] === $id) {
                return [...$action, 'name' => $record->name];
            }
        }

        return null;
    }

    private function enabled(string $code): ?PluginRecord
    {
        foreach ($this->records->enabled() as $record) {
            if ($record->code === $code) {
                $capabilities = $record->consent['capabilities'] ?? [];

                return is_array($capabilities) && in_array(Capability::Jobs->value, $capabilities, true) ? $record : null;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, label: string, description: string|null, job: string}>
     */
    private function declared(PluginRecord $record): array
    {
        $found = $this->registry->find($record->name);
        $manifest = $found instanceof FoundPlugin ? $found->manifest : null;
        $entries = $manifest instanceof Manifest ? ($manifest->contributes()['actions'] ?? null) : null;
        $actions = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && is_string($entry['label'] ?? null) && is_string($entry['job'] ?? null)) {
                $actions[] = [
                    'id' => $entry['id'],
                    'label' => $entry['label'],
                    'description' => is_string($entry['description'] ?? null) ? $entry['description'] : null,
                    'job' => $entry['job'],
                ];
            }
        }

        return $actions;
    }
}

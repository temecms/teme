<?php

/**
 * The actions a plugin declares for its settings page
 * (docs/proposals/plugin-sockets-1.md): buttons that queue one of its jobs,
 * offered only while it is enabled and granted jobs.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Lists;

use App\Domain\Plugins\Area\RowActionWords;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestText;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use Teme\Contracts\Plugins\Capability;

final readonly class PluginActions
{
    public function __construct(
        private PluginRecords $records,
        private PluginRegistry $registry,
    ) {}

    /**
     * Returns the actions of the plugin whose settings group this is, each
     * with its id, label and description in the current locale; none for any other group, or a
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
        $words = ManifestText::for($record->code);
        $locale = app()->getLocale();

        foreach ($this->declared($record) as $action) {
            // Row-only actions belong on area tables, never as settings buttons.
            if ($action['row'] === true) {
                continue;
            }

            $actions[] = [
                'id' => $action['id'],
                'label' => $words->text($action['label'], $locale),
                'description' => $action['description'] === null ? null : $words->text($action['description'], $locale),
            ];
        }

        return $actions;
    }

    /**
     * One declared action of an enabled plugin granted jobs, by the plugin's
     * code and the action's id; null when there is none. Confirm, toast and
     * queued are the plugin's words with core defaults already applied; confirm
     * title and toast may still hold {name} for the caller to fill.
     *
     * @return array{id: string, label: string, description: string|null, job: string, row: bool, confirm: array{title: string, text: string|null, button: string, cancel: string}|null, toast: string, queued: string, icon: string|null, name: string}|null
     */
    public function find(string $code, string $id): ?array
    {
        $record = $this->enabled($code);

        foreach ($record === null ? [] : $this->declared($record) as $action) {
            if ($action['id'] === $id) {
                $words = ManifestText::for($record->code);
                $locale = app()->getLocale();
                $translate = fn (string $english): string => $words->text($english, $locale);
                $label = $translate($action['label']);

                return [
                    ...$action,
                    'label' => $label,
                    'description' => $action['description'] === null ? null : $translate($action['description']),
                    'confirm' => RowActionWords::confirm($label, $action['confirm'], $translate),
                    'toast' => RowActionWords::toast($label, $action['toast'], $translate),
                    'queued' => RowActionWords::queued($label, $action['queued'], $translate),
                    'name' => $record->name,
                ];
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
     * @return list<array{id: string, label: string, description: string|null, job: string, row: bool, confirm: array{title?: string, text?: string, button?: string, cancel?: string}|string|null, toast: string|null, queued: string|null, icon: string|null}>
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
                    'row' => ($entry['row'] ?? false) === true,
                    'confirm' => $this->confirmOf($entry['confirm'] ?? null),
                    'toast' => is_string($entry['toast'] ?? null) ? $entry['toast'] : null,
                    'queued' => is_string($entry['queued'] ?? null) ? $entry['queued'] : null,
                    'icon' => is_string($entry['icon'] ?? null) ? $entry['icon'] : null,
                ];
            }
        }

        return $actions;
    }

    /**
     * @return array{title?: string, text?: string, button?: string, cancel?: string}|string|null
     */
    private function confirmOf(mixed $confirm): array|string|null
    {
        if (is_string($confirm)) {
            return $confirm;
        }

        if (! is_array($confirm)) {
            return null;
        }

        $out = [];

        foreach (['title', 'text', 'button', 'cancel'] as $key) {
            if (is_string($confirm[$key] ?? null) && trim($confirm[$key]) !== '') {
                $out[$key] = trim($confirm[$key]);
            }
        }

        return $out === [] ? null : $out;
    }
}

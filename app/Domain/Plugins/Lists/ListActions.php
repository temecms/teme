<?php

/**
 * The actions a plugin declares for the content lists (contributes.listActions):
 * buttons that queue one of its jobs as the person, on a row or on the
 * selected items. Offered only while the plugin is enabled and granted jobs.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Lists;

use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestText;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use Teme\Contracts\Plugins\Capability;

final readonly class ListActions
{
    public function __construct(
        private PluginRecords $records,
        private PluginRegistry $registry,
    ) {}

    /**
     * Every list action of every enabled plugin granted jobs, each with its
     * plugin's code and name, its words in the current locale, and whether it
     * is offered on rows, in bulk, or both; an empty list when there are none.
     *
     * @return list<array{code: string, name: string, id: string, label: string, row: bool, bulk: bool, confirm: string|null, job: string}>
     */
    public function all(): array
    {
        $actions = [];
        $locale = app()->getLocale();

        foreach ($this->records->enabled() as $record) {
            $capabilities = $record->consent['capabilities'] ?? [];

            if (! is_array($capabilities) || ! in_array(Capability::Jobs->value, $capabilities, true)) {
                continue;
            }

            $found = $this->registry->find($record->name);
            $manifest = $found instanceof FoundPlugin ? $found->manifest : null;
            $entries = $manifest instanceof Manifest ? ($manifest->contributes()['listActions'] ?? null) : null;
            $words = ManifestText::for($record->code);

            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! is_string($entry['label'] ?? null) || ! is_string($entry['job'] ?? null)) {
                    continue;
                }

                $actions[] = [
                    'code' => $record->code,
                    'name' => $record->name,
                    'id' => $entry['id'],
                    'label' => $words->text($entry['label'], $locale),
                    'row' => ($entry['row'] ?? false) === true,
                    'bulk' => ($entry['bulk'] ?? false) === true,
                    'confirm' => is_string($entry['confirm'] ?? null) ? $words->text($entry['confirm'], $locale) : null,
                    'job' => $entry['job'],
                ];
            }
        }

        return $actions;
    }

    /**
     * One list action of an enabled plugin granted jobs, by the plugin's code
     * and the action's id; null when there is none.
     *
     * @return array{code: string, name: string, id: string, label: string, row: bool, bulk: bool, confirm: string|null, job: string}|null
     */
    public function find(string $code, string $id): ?array
    {
        foreach ($this->all() as $action) {
            if ($action['code'] === $code && $action['id'] === $id) {
                return $action;
            }
        }

        return null;
    }
}

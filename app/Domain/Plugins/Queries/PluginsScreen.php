<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Queries;

use App\Domain\Ai\Plugins\DeclaredAiTask;
use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\PluginWords;
use App\Domain\Plugins\Tables\PluginMigrations;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Plugins\Capability;

/**
 * What Site › Plugins shows (I-3353, I-3442): every plugin from PluginList
 * with its badge, reason, the access it asks for in words and as the plain
 * lists the enable request posts back, what the person agreed to before,
 * which action the row offers, and the tables recorded for removing data.
 * It reads each plugin's files only as text and runs none of its code.
 */
final readonly class PluginsScreen
{
    /** The states a row in the Needs attention tab can be in. */
    private const array NEEDS_ATTENTION = [
        PluginState::Waiting,
        PluginState::CantBeUsed,
        PluginState::CodeGone,
        PluginState::StoppedByTeme,
        PluginState::SomePartsStopped,
    ];

    public function __construct(
        private PluginList $list,
        private PluginRegistry $registry,
        private PluginMigrations $migrations,
    ) {}

    /**
     * Returns every plugin as the page draws it.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $records = PluginRecord::query()->get()->keyBy('name');

        return array_map(function (PluginRow $row) use ($records): array {
            $record = $records->get($row->name);
            $enabled = $record !== null && $record->state === PluginRecord::ENABLED;
            $hosts = $row->asks['hosts'];
            $manifest = $this->registry->find($row->name)?->manifest;
            $newImports = $manifest !== null && $row->consent !== [] && array_key_exists('imports', Consent::added($row->consent, $manifest));
            $aiTasks = $manifest === null ? [] : PluginAiTasks::of($manifest);
            $newAiTasks = $row->consent === [] ? [] : ($row->added['aiTasks'] ?? []);

            $tables = $record === null ? [] : $this->tables($record);

            return [
                'name' => $row->name,
                'code' => $row->code,
                'label' => $row->label,
                'version' => $row->version,
                'description' => $row->description,
                'author' => $row->author,
                'authorUrl' => $row->authorUrl,
                'homepage' => $row->homepage,
                'state' => $row->state->value,
                'badge' => $row->state->label(),
                'reason' => $row->reason,
                'enabled' => $enabled,
                'needsAttention' => in_array($row->state, self::NEEDS_ATTENTION, true),
                'action' => $this->action($row, $enabled, $newImports || ($row->added['aiTasks'] ?? []) !== []),
                'hasSettings' => $this->labels($manifest?->contributes()['settings'] ?? null) !== [],
                'adds' => [...$this->labels($manifest?->contributes()['settings'] ?? null), ...$this->labels($manifest?->contributes()['admin'] ?? null)],
                'agreedVersion' => $record?->version,
                'agreed' => $this->accessLines($row->consent['capabilities'] ?? [], $row->consent['hosts'] ?? [], [], [], $row->consent['routes'] ?? [], [], Consent::agreedImports($row->consent), false, array_values(array_filter($aiTasks, fn (DeclaredAiTask $task): bool => array_key_exists($task->id, is_array($row->consent['aiTasks'] ?? null) ? $row->consent['aiTasks'] : [])))),
                // Everything is new at a first enable, so NEW marks only what an update adds to an earlier agreement.
                'asks' => $this->accessLines(
                    $row->asks['capabilities'],
                    $hosts,
                    $row->consent === [] ? [] : $row->added['capabilities'],
                    $row->consent === [] ? [] : $row->added['hosts'],
                    $manifest === null ? [] : Consent::routeLines($manifest),
                    $row->consent === [] ? [] : $row->added['routes'],
                    $manifest?->imports() ?? [],
                    $newImports,
                    $aiTasks,
                    $newAiTasks,
                ),
                'listensFor' => array_map(PluginWords::hook(...), $row->asks['hooks']),
                'shown' => ['version' => $row->version, ...$row->asks],
                'agreedBefore' => $row->consent !== [],
                'asksMore' => $row->added['capabilities'] !== [] || $row->added['hosts'] !== [] || $row->added['routes'] !== [] || $newImports || $newAiTasks !== [],
                'tables' => $tables,
                'ai' => $row->ai,
                // Same rule as RemovePluginData: disabled (or code gone) and tables left.
                'canRemoveData' => $tables !== [] && (! $enabled || $row->state === PluginState::CodeGone),
            ];
        }, $this->list->all());
    }

    /**
     * The plugin's recorded tables with approximate row counts (-1 unknown).
     *
     * @return list<array{name: string, rows: int}>
     */
    private function tables(PluginRecord $record): array
    {
        $names = $this->migrations->existingOwnedTables($record->migrations);

        if ($names === []) {
            return [];
        }

        $estimates = DB::table('pg_class')->whereIn('relname', $names)->pluck('reltuples', 'relname');

        return array_map(function (string $name) use ($estimates): array {
            $estimate = $estimates[$name] ?? null;
            $rows = is_numeric($estimate) && (float) $estimate >= 0 ? (int) round((float) $estimate) : -1;

            return ['name' => $name, 'rows' => $rows];
        }, $names);
    }

    /**
     * The names a plugin gave what it adds, as its manifest words them.
     *
     * @return list<string>
     */
    private function labels(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $labels = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['label'] ?? null) && $entry['label'] !== '') {
                $labels[] = $entry['label'];
            }
        }

        return $labels;
    }

    /**
     * The one button a row offers: enable, review access, run database
     * changes, disable, or none. $newImports also stands for AI tasks never
     * agreed to as they stand.
     *
     * @return 'enable'|'review'|'migrate'|'disable'|null
     */
    private function action(PluginRow $row, bool $enabled, bool $newImports): ?string
    {
        if ($row->state === PluginState::Waiting) {
            // Consent first: an update that asks for more access waits for that.
            return $row->added['capabilities'] !== [] || $row->added['hosts'] !== [] || $row->added['routes'] !== [] || $newImports ? 'review' : 'migrate';
        }

        if ($enabled) {
            return 'disable';
        }

        return in_array($row->state, [PluginState::NotEnabled, PluginState::Disabled, PluginState::StoppedByTeme], true) ? 'enable' : null;
    }

    /**
     * The sentences for a list of capabilities, then each declared public
     * route as `METHOD path`, then each AI task with what it sends, each with
     * whether it is new.
     *
     * @param  list<string>  $capabilities
     * @param  list<string>  $hosts
     * @param  list<string>  $newCapabilities
     * @param  list<string>  $newHosts
     * @param  list<string>  $routes
     * @param  list<string>  $newRoutes
     * @param  array{options?: list<string>, postTypes?: array<string, list<string>>, userMeta?: list<string>, termMeta?: list<string>}  $imports
     * @param  list<DeclaredAiTask>  $aiTasks
     * @param  list<string>  $newAiTasks
     *
     * @return list<array{words: string, new: bool}>
     */
    private function accessLines(array $capabilities, array $hosts, array $newCapabilities, array $newHosts = [], array $routes = [], array $newRoutes = [], array $imports = [], bool $newImports = false, array $aiTasks = [], array $newAiTasks = []): array
    {
        $labels = array_map(fn (DeclaredAiTask $task): string => $task->label, $aiTasks);
        $lines = array_map(fn (string $capability): array => [
            'words' => PluginWords::capability($capability, $hosts, $imports, $labels),
            'new' => in_array($capability, $newCapabilities, true) || ($capability === 'http' && $newHosts !== []) || ($capability === Capability::ImportWordPress->value && $newImports),
        ], $capabilities);

        if (in_array(Capability::Ai->value, $capabilities, true)) {
            foreach ($aiTasks as $task) {
                $lines[] = ['words' => PluginWords::aiTask($task), 'new' => in_array($task->id, $newAiTasks, true)];
            }
        }

        if (! in_array(Capability::RoutesPublic->value, $capabilities, true)) {
            return $lines;
        }

        foreach ($routes as $route) {
            $lines[] = [
                'words' => $route,
                'new' => in_array($route, $newRoutes, true),
            ];
        }

        return $lines;
    }
}

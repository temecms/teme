<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Queries;

use App\Domain\Extensions\Queries\ExtensionsScreen;
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
        private ExtensionsScreen $extensions,
    ) {}

    /**
     * Returns every plugin as the page draws it.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $records = PluginRecord::query()->get()->keyBy('name');
        $rows = $this->list->all();
        $paths = [];

        foreach ($rows as $row) {
            $found = $this->registry->find($row->name);

            if ($found !== null) {
                $paths[$row->name] = $found->path;
            }
        }

        $origins = $this->extensions->origins($paths);

        return array_map(function (PluginRow $row) use ($records, $origins): array {
            $record = $records->get($row->name);
            $enabled = $record !== null && $record->state === PluginRecord::ENABLED;
            $hosts = $row->asks['hosts'];
            $manifest = $this->registry->find($row->name)?->manifest;
            $newImports = $manifest !== null && $row->consent !== [] && array_key_exists('imports', Consent::added($row->consent, $manifest));

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
                // Why its schedules don't run, shown as a warning in its row (docs/proposals/plugin-sockets-1.md).
                'schedules' => $row->schedules,
                'enabled' => $enabled,
                'needsAttention' => in_array($row->state, self::NEEDS_ATTENTION, true),
                'action' => $this->action($row, $enabled, $newImports),
                'hasSettings' => $this->labels($manifest?->contributes()['settings'] ?? null) !== [],
                'adds' => [...$this->labels($manifest?->contributes()['settings'] ?? null), ...$this->labels($manifest?->contributes()['admin'] ?? null)],
                'agreedVersion' => $record?->version,
                'agreed' => [
                    ...$this->accessLines($row->consent['capabilities'] ?? [], $row->consent['hosts'] ?? [], [], [], $row->consent['routes'] ?? [], [], Consent::agreedImports($row->consent)),
                    ...$this->insertionHostsLine($this->agreedInsertionHosts($row->consent), false),
                ],
                // Everything is new at a first enable, so NEW marks only what an update adds to an earlier agreement.
                'asks' => [...$this->accessLines(
                    $row->asks['capabilities'],
                    $hosts,
                    $row->consent === [] ? [] : $row->added['capabilities'],
                    $row->consent === [] ? [] : $row->added['hosts'],
                    $manifest === null ? [] : Consent::routeLines($manifest),
                    $row->consent === [] ? [] : $row->added['routes'],
                    $manifest?->imports() ?? [],
                    $newImports,
                ), ...$this->insertionHostsLine(
                    $manifest === null ? [] : array_merge(...array_values(Consent::declaredInsertionHosts($manifest))),
                    $row->consent !== [] && ($row->added['insertionHosts'] ?? []) !== [],
                )],
                'listensFor' => array_map(PluginWords::hook(...), $row->asks['hooks']),
                'shown' => ['version' => $row->version, ...$row->asks],
                'agreedBefore' => $row->consent !== [],
                'asksMore' => $row->added['capabilities'] !== [] || $row->added['hosts'] !== [] || $row->added['routes'] !== [] || ($row->added['insertionHosts'] ?? []) !== [] || $newImports,
                'tables' => $tables,
                // Same rule as RemovePluginData: disabled (or code gone) and tables left.
                'canRemoveData' => $tables !== [] && (! $enabled || $row->state === PluginState::CodeGone),
                // Installed from the admin, or part of the site's build (I-211).
                'origin' => $origins[$row->name]['origin'] ?? 'image',
                'source' => $origins[$row->name]['source'] ?? null,
                // Same rule as ForgetPlugin: disabled, package gone, and no tables left.
                'canForget' => ! $enabled && ($manifest === null) && ($record === null || $this->migrations->ownedTables($record->migrations) === []),
            ];
        }, $rows);
    }

    /**
     * The line naming the hosts a plugin's inserted content loads from, or
     * none when it declares none (docs/plans/plugin-sockets-2.md, B3).
     *
     * @param  list<string>  $hosts
     *
     * @return list<array{words: string, new: bool}>
     */
    private function insertionHostsLine(array $hosts, bool $new): array
    {
        $hosts = array_values(array_unique($hosts));

        return $hosts === [] ? [] : [['words' => PluginWords::insertionHosts($hosts), 'new' => $new]];
    }

    /**
     * The hosts agreed to for a plugin's inserted content, of every kind.
     *
     * @param  array<string, mixed>  $consent
     *
     * @return list<string>
     */
    private function agreedInsertionHosts(array $consent): array
    {
        $hosts = [];

        foreach (is_array($consent['csp'] ?? null) ? $consent['csp'] : [] as $listed) {
            foreach (is_array($listed) ? $listed : [] as $host) {
                if (is_string($host)) {
                    $hosts[] = $host;
                }
            }
        }

        return $hosts;
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
     * changes, disable, or none.
     *
     * @return 'enable'|'review'|'migrate'|'disable'|null
     */
    private function action(PluginRow $row, bool $enabled, bool $newImports): ?string
    {
        if ($row->state === PluginState::Waiting) {
            // Consent first: an update that asks for more access waits for that.
            return $row->added['capabilities'] !== [] || $row->added['hosts'] !== [] || $row->added['routes'] !== [] || ($row->added['insertionHosts'] ?? []) !== [] || $newImports ? 'review' : 'migrate';
        }

        if ($enabled) {
            return 'disable';
        }

        return in_array($row->state, [PluginState::NotEnabled, PluginState::Disabled, PluginState::StoppedByTeme], true) ? 'enable' : null;
    }

    /**
     * The sentences for a list of capabilities, then each declared public
     * route as `METHOD path`, each with whether it is new.
     *
     * @param  list<string>  $capabilities
     * @param  list<string>  $hosts
     * @param  list<string>  $newCapabilities
     * @param  list<string>  $newHosts
     * @param  list<string>  $routes
     * @param  list<string>  $newRoutes
     * @param  array{options?: list<string>, postTypes?: array<string, list<string>>, userMeta?: list<string>, termMeta?: list<string>}  $imports
     *
     * @return list<array{words: string, new: bool}>
     */
    private function accessLines(array $capabilities, array $hosts, array $newCapabilities, array $newHosts = [], array $routes = [], array $newRoutes = [], array $imports = [], bool $newImports = false): array
    {
        $lines = array_map(fn (string $capability): array => [
            'words' => PluginWords::capability($capability, $hosts, $imports),
            'new' => in_array($capability, $newCapabilities, true) || ($capability === 'http' && $newHosts !== []) || ($capability === Capability::ImportWordPress->value && $newImports),
        ], $capabilities);

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

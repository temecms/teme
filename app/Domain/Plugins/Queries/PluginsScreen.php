<?php

/**
 * What Site › Plugins shows (I-3353, I-3442): every plugin from PluginList
 * with its badge, reason, the access it asks for in words and as the plain
 * lists the enable request posts back, what the person agreed to before,
 * which action the row offers, and the tables recorded for removing data;
 * its manifest's words in the person's language.
 * It reads each plugin's files only as text and runs none of its code.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Queries;

use App\Domain\Ai\Plugins\DeclaredAiTask;
use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Extensions\Queries\ExtensionsScreen;
use App\Domain\Plugins\Area\PluginPermissions;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Downloads\DeclaredDownloads;
use App\Domain\Plugins\Editor\DeclaredPanel;
use App\Domain\Plugins\Editor\PanelValidator;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestText;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Notices\DeclaredNotices;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\PluginWords;
use App\Domain\Plugins\Scripts\ScriptSize;
use App\Domain\Plugins\SecurityLines\DeclaredSecurityKinds;
use App\Domain\Plugins\Seo\SeoDeclarations;
use App\Domain\Plugins\Tables\PluginMigrations;
use App\Domain\Settings\Models\SettingsGroupRecord;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Plugins\Capability;

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
            $aiTasks = $manifest === null ? [] : PluginAiTasks::of($manifest);
            $newAiTasks = $row->consent === [] ? [] : ($row->added['aiTasks'] ?? []);

            $tables = $record === null ? [] : $this->tables($record);
            $saved = SettingsGroupRecord::query()->find('plugin.'.$row->code);
            $savedData = ['saved' => $saved !== null, 'secrets' => count($saved->secrets ?? [])];
            // Its manifest's words in the person's language, from its own lang folder (docs/plans/plugin-sockets-2.md).
            $words = ManifestText::for($row->code);
            $locale = app()->getLocale();
            $notices = $manifest === null ? [] : array_column(DeclaredNotices::of($manifest), 'label');
            $securityKinds = $manifest === null ? [] : array_values(DeclaredSecurityKinds::of($manifest));
            $downloadBytes = $manifest === null ? null : DeclaredDownloads::declared($manifest);

            return [
                'name' => $row->name,
                'code' => $row->code,
                'label' => $words->text($row->label, $locale),
                'version' => $row->version,
                'description' => $words->text($row->description, $locale),
                'author' => $row->author,
                'authorUrl' => $row->authorUrl,
                'homepage' => $row->homepage,
                'icon' => $row->icon,
                'state' => $row->state->value,
                'badge' => $row->state->label(),
                'reason' => $row->reason,
                // Why its schedules don't run, shown as a warning in its row (docs/proposals/plugin-sockets-1.md).
                'schedules' => $row->schedules,
                // How big its declared scripts are, compressed, and whether they pass the budget: a warning, never a block (docs/guides/developers/plugins/scripts.md).
                'scripts' => ScriptSize::of($this->registry->find($row->name)),
                'enabled' => $enabled,
                'needsAttention' => in_array($row->state, self::NEEDS_ATTENTION, true),
                'action' => $this->action($row, $enabled, $newImports || ($row->added['aiTasks'] ?? []) !== []),
                'hasSettings' => $this->labels($manifest?->contributes()['settings'] ?? null) !== [],
                'adds' => array_map(fn (string $label): string => $words->text($label, $locale), [...$this->labels($manifest?->contributes()['settings'] ?? null), ...$this->labels($manifest?->contributes()['admin'] ?? null)]),
                'agreedVersion' => $record?->version,
                'agreed' => [
                    ...$this->accessLines($row->consent['capabilities'] ?? [], $row->consent['hosts'] ?? [], [], [], $row->consent['routes'] ?? [], [], Consent::agreedImports($row->consent), false, array_values(array_filter($aiTasks, fn (DeclaredAiTask $task): bool => array_key_exists($task->id, is_array($row->consent['aiTasks'] ?? null) ? $row->consent['aiTasks'] : []))), notices: $notices, securityKinds: $securityKinds, downloadBytes: isset($row->consent['downloads']) ? $row->consent['downloads']['maxBytes'] : null),
                    ...$this->insertionHostsLine($this->agreedInsertionHosts($row->consent), false),
                    ...$this->permissionLines($manifest, (array) ($row->consent['permissions'] ?? []), false),
                    ...$this->panelLines($manifest, $row->consent['capabilities'] ?? []),
                    ...$this->seoLines($manifest, $row->consent['capabilities'] ?? []),
                ],
                // Everything is new at a first enable, so NEW marks only what an update adds to an earlier agreement.
                'asks' => [...$this->accessLines(
                    $row->asks['capabilities'],
                    $hosts,
                    // A kind of notice no one agreed to marks the notices line NEW.
                    $row->consent === [] ? [] : [...$row->added['capabilities'], ...(($row->added['notices'] ?? []) === [] ? [] : [Capability::NoticesSend->value])],
                    $row->consent === [] ? [] : $row->added['hosts'],
                    $manifest === null ? [] : Consent::routeLines($manifest),
                    $row->consent === [] ? [] : $row->added['routes'],
                    $manifest?->imports() ?? [],
                    $newImports,
                    $aiTasks,
                    $newAiTasks,
                    notices: $notices,
                    securityKinds: $securityKinds,
                    downloadBytes: $downloadBytes,
                ), ...$this->insertionHostsLine(
                    $manifest === null ? [] : array_merge(...array_values(Consent::declaredInsertionHosts($manifest))),
                    $row->consent !== [] && ($row->added['insertionHosts'] ?? []) !== [],
                ), ...$this->permissionLines($manifest, null, $row->consent !== [] && ($row->added['permissions'] ?? []) !== []), ...$this->panelLines($manifest, null), ...$this->seoLines($manifest, null)],
                'listensFor' => array_map(PluginWords::hook(...), $row->asks['hooks']),
                // The plugins it needs, then those that need it, by their own names (docs/plans/plugin-sockets-3.md).
                'requires' => array_map(fn (string $name): array => [
                    'words' => $this->labelOf($name),
                    'new' => $row->consent !== [] && in_array($name, $row->added['requires'] ?? [], true),
                ], $manifest === null ? [] : Consent::declaredRequires($manifest)),
                'requiredBy' => array_map($this->labelOf(...), $row->requiredBy),
                'shown' => ['version' => $row->version, ...$row->asks],
                'agreedBefore' => $row->consent !== [],
                'asksMore' => $row->added['capabilities'] !== [] || $row->added['hosts'] !== [] || $row->added['routes'] !== [] || ($row->added['insertionHosts'] ?? []) !== [] || ($row->added['permissions'] ?? []) !== [] || $newImports || $newAiTasks !== [] || ($row->added['notices'] ?? []) !== [] || ($row->added['requires'] ?? []) !== [],
                'tables' => $tables,
                // What removing its data also takes: its saved settings, and how many secrets.
                'savedData' => $savedData,
                'ai' => $row->ai,
                // Same rule as RemovePluginData: disabled (or code gone) and tables or saved settings left.
                'canRemoveData' => ($tables !== [] || $savedData['saved']) && (! $enabled || $row->state === PluginState::CodeGone),
                // Installed from the admin, or part of the site's build (I-211).
                'origin' => $origins[$row->name]['origin'] ?? 'image',
                'source' => $origins[$row->name]['source'] ?? null,
                // Same rule as ForgetPlugin: disabled, package gone, and no tables left.
                'canForget' => ! $enabled && ($manifest === null) && ($record === null || $this->migrations->ownedTables($record->migrations) === []),
            ];
        }, $rows);
    }

    /**
     * A line for each permission the plugin declares, who it is given to and
     * what it lets them do (docs/plans/plugin-sockets-2.md, A1); with
     * $agreed, only those whose id was agreed.
     *
     * @param  list<mixed>|null  $agreed
     *
     * @return list<array{words: string, new: bool}>
     */
    private function permissionLines(?Manifest $manifest, ?array $agreed, bool $new): array
    {
        if ($manifest === null) {
            return [];
        }

        $lines = [];

        foreach (PluginPermissions::declared($manifest) as $index => $permission) {
            if ($agreed === null || in_array($permission['id'], $agreed, true)) {
                $lines[] = ['words' => PluginPermissions::consentWords($manifest)[$index], 'new' => $new];
            }
        }

        return $lines;
    }

    /**
     * One line for each editor panel the manifest declares; for what was
     * agreed, only once both capabilities a panel needs were.
     *
     * @param  list<string>|null  $agreed  the capabilities agreed, or null for what it asks
     *
     * @return list<array{words: string, new: bool}>
     */
    private function panelLines(?Manifest $manifest, ?array $agreed): array
    {
        if ($manifest === null || ($agreed !== null && array_diff(array_column(PanelValidator::NEEDS, 'value'), $agreed) !== [])) {
            return [];
        }

        return array_map(fn (string $words): array => ['words' => $words, 'new' => false], DeclaredPanel::consentWords($manifest));
    }

    /**
     * The lines for what the manifest declares for search engines: its
     * checks, its Search and social tab and its sitemaps
     * (docs/guides/developers/plugins/seo.md).
     *
     * @param  list<string>|null  $agreed  the capabilities agreed, or null for what it asks
     *
     * @return list<array{words: string, new: bool}>
     */
    private function seoLines(?Manifest $manifest, ?array $agreed): array
    {
        return $manifest === null ? [] : array_map(fn (string $words): array => ['words' => $words, 'new' => false], SeoDeclarations::consentWords($manifest, $agreed));
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
     * A found plugin's own label, or its Composer name when it isn't found.
     */
    private function labelOf(string $name): string
    {
        return $this->registry->find($name)?->manifest?->label() ?? $name;
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
            return $row->added['capabilities'] !== [] || $row->added['hosts'] !== [] || $row->added['routes'] !== [] || ($row->added['insertionHosts'] ?? []) !== [] || ($row->added['permissions'] ?? []) !== [] || $newImports || ($row->added['notices'] ?? []) !== [] || ($row->added['requires'] ?? []) !== [] ? 'review' : 'migrate';
        }

        if ($enabled) {
            return self::addsOnlyWiderPeopleRead($row, $newImports) ? 'review' : 'disable';
        }

        return in_array($row->state, [PluginState::NotEnabled, PluginState::Disabled, PluginState::StoppedByTeme], true) ? 'enable' : null;
    }

    /**
     * Whether an Enabled plugin's only addition is the wider people.read:
     * an older grant keeps running, and agreeing again adds the invite and
     * join events and owner names, so its row offers Review access.
     */
    private static function addsOnlyWiderPeopleRead(PluginRow $row, bool $newImports): bool
    {
        if ($row->consent === [] || $row->added['capabilities'] !== [Capability::PeopleRead->value] || $newImports) {
            return false;
        }

        foreach (['hosts', 'routes', 'insertionHosts', 'permissions', 'notices', 'requires'] as $kind) {
            if (($row->added[$kind] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The sentences for a list of capabilities, then each declared public
     * route in words (`METHOD path` is how it is stored), then each AI task with what it sends, each with
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
     * @param  list<string>  $notices  the labels of the kinds of notice it declares
     * @param  list<string>  $securityKinds  the labels of the kinds of security log line it declares
     *
     * @return list<array{words: string, new: bool}>
     */
    private function accessLines(array $capabilities, array $hosts, array $newCapabilities, array $newHosts = [], array $routes = [], array $newRoutes = [], array $imports = [], bool $newImports = false, array $aiTasks = [], array $newAiTasks = [], array $notices = [], array $securityKinds = [], ?int $downloadBytes = null): array
    {
        $labels = array_map(fn (DeclaredAiTask $task): string => $task->label, $aiTasks);
        $lines = array_map(fn (string $capability): array => [
            'words' => PluginWords::capability($capability, $hosts, $imports, $labels, $notices, $securityKinds, $downloadBytes),
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
                'words' => PluginWords::route($route),
                'new' => in_array($route, $newRoutes, true),
            ];
        }

        return $lines;
    }
}

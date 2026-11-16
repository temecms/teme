<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Queries;

use App\Domain\Ai\Plugins\PluginAiSection;
use App\Domain\Ai\Plugins\PluginAiSpend;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Jobs\ScheduleRunner;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestValidator;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Tables\PluginMigrations;
use App\Domain\Site\SiteDates;
use App\Models\User;
use Composer\Semver\Semver;
use Teme\Contracts\Plugins\Capability;
use UnexpectedValueException;

/**
 * Every plugin, for the console, the Status banner and the Plugins page
 * (I-3353): each one found, and each row whose code is gone, with where it
 * stands and why. It reads code only from its source, never running it.
 */
final readonly class PluginList
{
    public function __construct(
        private PluginRegistry $registry,
        private ManifestValidator $validator,
        private LoadedPlugins $loaded,
        private PluginLoader $loader,
        private PluginMigrations $migrations,
        private ScheduleRunner $schedules,
        private PluginAiSpend $aiSpend,
        private PluginAiSection $aiSection,
    ) {}

    /**
     * Returns every plugin found and every row whose code is gone, ordered by name.
     *
     * @return list<PluginRow>
     */
    public function all(): array
    {
        $found = $this->registry->all();
        $records = PluginRecord::query()->orderBy('name')->get()->keyBy('name')->all();
        $names = array_unique([...array_keys($found), ...array_keys($records)]);
        sort($names);

        $people = User::query()->whereIn('id', array_filter(array_map(fn (PluginRecord $record): ?int => $record->disabled_by, $records)))->pluck('name', 'id')->all();
        $disabledOnServer = $this->loader->disabledOnServer();

        return array_map(fn (string $name): PluginRow => $this->row($name, $found[$name] ?? null, $records[$name] ?? null, $people, $disabledOnServer), $names);
    }

    /**
     * @param  array<int, string>  $people
     * @param  true|list<string>  $disabledOnServer
     */
    private function row(string $name, ?FoundPlugin $plugin, ?PluginRecord $record, array $people, true|array $disabledOnServer): PluginRow
    {
        $manifest = $plugin?->manifest;
        $consent = $record === null ? [] : $record->consent;
        $stopped = $record === null || $manifest === null ? [] : $this->stopped($record, $manifest);
        [$state, $reason] = $this->state($name, $plugin, $record, $stopped, $people, $disabledOnServer);

        return new PluginRow(
            name: $name,
            code: $plugin->code ?? $record->code ?? '',
            label: $manifest?->label() ?? $name,
            version: $manifest?->version() ?? $record->version ?? '',
            description: $manifest?->description() ?? '',
            author: $manifest?->author() ?? '',
            authorUrl: $manifest?->authorUrl() ?? '',
            homepage: $manifest?->homepage() ?? '',
            state: $state,
            reason: $reason,
            consent: $consent,
            asks: ['capabilities' => $manifest?->capabilities() ?? [], 'hosts' => $manifest?->hosts() ?? [], 'hooks' => $manifest?->hooks() ?? []],
            added: $manifest === null ? ['capabilities' => [], 'hosts' => [], 'routes' => []] : Consent::added($consent, $manifest),
            stopped: array_keys($stopped),
            schedules: $record === null ? null : $this->schedules->problem($record),
            ai: in_array(Capability::Ai->value, $consent['capabilities'] ?? [], true) && $plugin !== null ? $this->ai($plugin->code) : null,
        );
    }

    /**
     * This month's AI cost for a plugin granted ai, and its own budget.
     *
     * @return array{costMicrounits: int, budgetMicrounits: int|null}
     */
    private function ai(string $code): array
    {
        return ['costMicrounits' => $this->aiSpend->month($code)->costMicrounits, 'budgetMicrounits' => $this->aiSection->budgetMicrounits($code)];
    }

    /**
     * Where the plugin stands, and the reason line.
     *
     * @param  array<string, array<string, mixed>>  $stopped
     * @param  array<int, string>  $people
     * @param  true|list<string>  $disabledOnServer
     *
     * @return array{0: PluginState, 1: string}
     */
    private function state(string $name, ?FoundPlugin $plugin, ?PluginRecord $record, array $stopped, array $people, true|array $disabledOnServer): array
    {
        $cantBeUsed = $plugin === null ? null : $this->cantBeUsed($plugin);

        if ($record === null) {
            return $cantBeUsed === null ? [PluginState::NotEnabled, __('Installed, not enabled yet.')] : [PluginState::CantBeUsed, $cantBeUsed];
        }

        if ($record->state === PluginRecord::DISABLED) {
            return $this->disabled($name, $record, $people);
        }

        if ($disabledOnServer === true || in_array($name, $disabledOnServer, true)) {
            return [PluginState::DisabledOnServer, __('Disabled on the server.')];
        }

        if ($plugin === null || $plugin->manifest === null) {
            return [PluginState::CodeGone, __('Its code is no longer installed. Its settings are kept.')];
        }

        if ($cantBeUsed !== null) {
            return [PluginState::CantBeUsed, $cantBeUsed];
        }

        $manifest = $plugin->manifest;

        if (! Consent::covers($record->consent, $manifest)) {
            return [PluginState::Waiting, __('Update :version is blocked: it asks for more access than you agreed to.', ['version' => $manifest->version()])];
        }

        if ($this->migrations->waiting($plugin, $record->migrations) !== []) {
            return [PluginState::Waiting, __('Version :version has database changes to run.', ['version' => $manifest->version()])];
        }

        $failure = $this->loaded->failure($name);

        if ($failure !== null) {
            return [PluginState::CantBeUsed, __('It failed to start: :error', ['error' => $failure])];
        }

        if ($stopped !== []) {
            $error = (string) (end($stopped)['error'] ?? '');

            return [PluginState::SomePartsStopped, count($stopped) === 1
                ? __('1 part stopped after repeated errors: :error It starts again when the plugin is updated, or disabled and enabled again.', ['error' => $error])
                : __(':n parts stopped after repeated errors: :error They start again when the plugin is updated, or disabled and enabled again.', ['n' => count($stopped), 'error' => $error])];
        }

        if ($record->version !== $manifest->version()) {
            return [PluginState::Enabled, __('Updated from :old to :new; access unchanged.', ['old' => $record->version, 'new' => $manifest->version()])];
        }

        return [PluginState::Enabled, ''];
    }

    /**
     * A disabled row: by Teme, or by a person or the server's console.
     *
     * @param  array<int, string>  $people
     *
     * @return array{0: PluginState, 1: string}
     */
    private function disabled(string $name, PluginRecord $record, array $people): array
    {
        if ($record->disabled_reason === PluginRecord::QUARANTINED) {
            $error = $record->faults[$name.'#boot']['error'] ?? $record->quarantine[$name.'#boot']['error'] ?? '';

            return [PluginState::StoppedByTeme, __('Teme disabled it after it failed to start five times: :error', ['error' => (string) $error])];
        }

        $date = $record->disabled_at === null ? '' : SiteDates::inSite($record->disabled_at)->format('j F Y');

        return [PluginState::Disabled, $record->disabled_by === null
            ? __('Disabled from the server’s console on :date.', ['date' => $date])
            : __('Disabled by :name on :date.', ['name' => $people[$record->disabled_by] ?? '', 'date' => $date])];
    }

    /**
     * Why the plugin can't be used, or null: the Teme version it needs, else
     * its first problem, with how many more there are.
     */
    private function cantBeUsed(FoundPlugin $plugin): ?string
    {
        $constraint = $plugin->manifest?->teme() ?? '';
        $version = (string) config('teme.version');

        try {
            if ($constraint !== '' && ! Semver::satisfies($version, $constraint)) {
                return __('It needs Teme :constraint; this is Teme :version.', ['constraint' => $constraint, 'version' => $version]);
            }
        } catch (UnexpectedValueException) {
            // Not a constraint: the manifest's own problem says so below.
        }

        $problems = array_values(array_unique([...$plugin->problems, ...$this->validator->liveErrors($plugin)]));

        if ($problems === []) {
            return null;
        }

        return count($problems) === 1 ? $problems[0] : $problems[0].' '.__('(and :n more)', ['n' => count($problems) - 1]);
    }

    /**
     * The row's stopped parts for the version found now: an update lifts them.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stopped(PluginRecord $record, Manifest $manifest): array
    {
        return array_filter($record->quarantine, fn (mixed $entry): bool => is_array($entry) && ($entry['version'] ?? null) === $manifest->version());
    }
}

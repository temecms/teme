<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Plugins\Area\PluginPermissions;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Jobs\ScheduleRunner;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SettingHosts;
use App\Domain\Plugins\Tables\PluginMigrations;
use App\Domain\Plugins\Tables\PluginTablesRefused;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\PublicRoutes\RootFile;
use App\Domain\PublicRoutes\RouteCollisions;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Insertions\InsertionsChanged;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;
use Teme\Contracts\Plugins\Capability;

/**
 * Enables a plugin (I-3353, I-3442): a person who holds plugins.manage, after
 * their password, or the operator. Its manifest is checked and its code
 * scanned afresh, never from the cache, and what the person agreed to must
 * be what it asks for now; none of its public routes may collide with one
 * that answers already. Runs its waiting migrations inside the same
 * transaction, records that consent, for this exact version and by whom,
 * and clears its faults and stopped parts. Returns its name.
 */
final class EnablePlugin extends BaseOperation implements RequiresElevation
{
    use AuthorizesPluginChanges;

    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginRecords $records,
        private readonly PluginMigrations $migrations,
        private readonly SecurityLog $log,
        private readonly PublicRouteRegistry $publicRoutes,
        private readonly RouteCollisions $routeCollisions,
        private readonly SettingHosts $settingHosts,
        private readonly ScheduleRunner $schedules,
    ) {}

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void
    {
        /** @var EnablePluginInput $input */
        $found = $this->registry->find($input->name);

        if ($found === null) {
            throw ValidationException::withMessages(['plugin' => ["No plugin is named \"{$input->name}\"."]]);
        }

        $plugin = $this->registry->fresh($found);
        $manifest = $plugin->manifest;

        if ($plugin->problems !== [] || $manifest === null) {
            throw ValidationException::withMessages(['plugin' => [$plugin->problems[0] ?? 'The plugin has no teme-plugin.json.']]);
        }

        $same = $input->version === $manifest->version()
            && $this->sameSet($input->capabilities, $manifest->capabilities())
            && $this->sameSet($input->hosts, $manifest->hosts())
            && $this->sameSet($input->hooks, $manifest->hooks());

        // The hosts its settings name, when shown, are those they name now.
        $same = $same && ($input->settingHosts === [] || $this->sameSet($input->settingHosts, $this->settingHosts->current($manifest)));

        if (! $same) {
            throw ValidationException::withMessages(['plugin' => ['It asks for something different now. Look again before you enable it.']]);
        }

        // Two owners never answer one request: refused before anything is written (docs/proposals/plugin-public-routes.md).
        $enabled = PluginRecord::query()->where('state', PluginRecord::ENABLED)->orderBy('name')->get()->all();
        $collisions = $this->routeCollisions->against($this->publicRoutes->declaredBy($enabled), $manifest);

        // Nor one root file (docs/guides/developers/plugins/root-files.md).
        $others = array_values(array_filter(array_map(fn (PluginRecord $record): ?Manifest => $this->registry->find($record->name)?->manifest, $enabled)));
        $collisions = [...$collisions, ...$this->routeCollisions->rootFilesAgainst($others, $manifest)];

        if ($collisions !== []) {
            throw ValidationException::withMessages(['plugin' => $collisions]);
        }

        // The administrator the operator names for its schedules must still manage plugins (docs/proposals/plugin-sockets-1.md).
        if ($input->schedulesAs !== null && ! $this->schedules->mayManage($input->schedulesAs)) {
            throw ValidationException::withMessages(['plugin' => [__('No one who may manage plugins has that account.')]]);
        }

        $taken = PluginRecord::query()->where('code', $plugin->code)->where('name', '!=', $plugin->name)->value('name');

        if (is_string($taken)) {
            throw ValidationException::withMessages(['plugin' => ["The plugin code \"{$plugin->code}\" is already used by {$taken}."]]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var EnablePluginInput $input */
        $now = CarbonImmutable::now();
        $code = (string) $this->registry->find($input->name)?->code;

        // The first time, a disabled row with every required column, then the real values under the lock.
        DB::table('plugins')->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'name' => $input->name,
            'code' => $code,
            'state' => PluginRecord::DISABLED,
            'disabled_reason' => PluginRecord::BY_USER,
            'version' => $input->version,
            'consent' => '{}',
            'consented_at' => $now,
            'faults' => '{}',
            'quarantine' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $record = PluginRecord::query()->where('name', $input->name)->lockForUpdate()->sole();
        $found = $this->registry->find($input->name) ?? throw ValidationException::withMessages(['plugin' => ["No plugin is named \"{$input->name}\"."]]);
        $manifest = $found->manifest ?? throw ValidationException::withMessages(['plugin' => ["No plugin is named \"{$input->name}\"."]]);

        try {
            $migrations = $this->migrations->run($found, $record->migrations);
        } catch (PluginTablesRefused $refused) {
            throw ValidationException::withMessages(['plugin' => [$refused->getMessage()]]);
        }

        $consentedBy = $input->actorId() ?? $input->schedulesAs;

        $record->fill([
            'code' => $code,
            'state' => PluginRecord::ENABLED,
            'disabled_reason' => null,
            'disabled_at' => null,
            'disabled_by' => null,
            'version' => $input->version,
            'consent' => [
                'capabilities' => $input->capabilities,
                'hosts' => $input->hosts,
                'hooks' => $input->hooks,
                'routes' => Consent::routeLines($manifest),
                ...$this->insertionHosts($manifest, $input->capabilities),
                ...$this->imports($found),
                ...$this->hostsFrom($manifest, $record->consent, $input->settingHosts),
                ...$this->permissions($manifest),
            ],
            // Its schedules run as this person: who enabled it, or whom the operator named.
            'consented_by' => $consentedBy === null ? null : (int) $consentedBy,
            'consented_at' => $now,
            'enabled_at' => $now,
            // Enabling again is the manual clear of what Teme stopped.
            'faults' => [],
            'quarantine' => [],
            'migrations' => $migrations,
        ])->save();

        // Its permissions, made once and given to administrators and the roles it names (docs/plans/plugin-sockets-2.md, A1).
        PluginPermissions::ensure($code, PluginPermissions::declared($manifest));

        $this->log->record(SecurityEventType::PluginEnabled, $input->actorId(), context: [
            'plugin' => $input->name,
            'version' => $input->version,
            'capabilities' => $input->capabilities,
            'hosts' => $input->hosts,
            ...($input->actorId() === null && $input->schedulesAs !== null ? ['schedules_as' => $input->schedulesAs] : []),
        ]);
        // A stored "not found" at an address its root files now answer goes too (docs/guides/developers/plugins/root-files.md).
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'plugin:'.$code, ['plugin:'.$code, ...(RootFile::declaredIn($manifest) === [] ? [] : [PageTags::ROOT_FILES])]));

        // Every stored feed is drawn again with its links, after commit (docs/plans/plugin-sockets-1.md, A3).
        if (($manifest->contributes()['feedLinks'] ?? []) !== []) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'plugin:'.$code, [PageTags::FEED_LINKS]));
        }

        // Pages drawn before it was enabled are drawn again with its insertions, after commit (I-064).
        if (in_array(Capability::ContentInsert->value, $input->capabilities, true) || in_array(Capability::PagesInsert->value, $input->capabilities, true)) {
            app(InsertionsChanged::class)->notify();
        }

        DB::afterCommit(fn () => $this->records->changed());

        return $input->name;
    }

    /**
     * The permissions it declares, kept with the consent so a new one, or a
     * new role given one, asks again; none for a plugin that declares none.
     *
     * @return array{permissions?: list<string>}
     */
    private function permissions(Manifest $manifest): array
    {
        $lines = PluginPermissions::consentLines($manifest);

        return $lines === [] ? [] : ['permissions' => $lines];
    }

    /**
     * The names the plugin declares to import, kept with the consent so an
     * import hands it only those agreed to (I-349); nothing when it declares none.
     *
     * @return array{}|array{imports: array{options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}}
     */
    private function imports(FoundPlugin $found): array
    {
        $declared = $found->manifest === null ? [] : Consent::declaredImports($found->manifest);

        return $declared === [] ? [] : ['imports' => $declared];
    }

    /**
     * The settings it takes hosts from and the hosts agreed in them, kept
     * with the consent (docs/plans/plugin-sockets-1.md, A2): those agreed now,
     * and those a person agreed before, on its settings page; nothing when it
     * takes none.
     *
     * @param  array<string, mixed>  $before  the consent recorded before
     * @param  list<string>  $agreed
     *
     * @return array{}|array{hostsFrom: list<string>, settingHosts: list<string>, settingHostsAgreed?: list<mixed>}
     */
    private function hostsFrom(Manifest $manifest, array $before, array $agreed): array
    {
        $keys = SettingHosts::declared($manifest);

        if ($keys === []) {
            return [];
        }

        $earlier = array_values(array_filter((array) ($before['settingHosts'] ?? []), is_string(...)));
        $history = is_array($before['settingHostsAgreed'] ?? null) ? array_values($before['settingHostsAgreed']) : [];

        return [
            'hostsFrom' => $keys,
            'settingHosts' => array_values(array_unique([...$earlier, ...$agreed])),
            ...($history === [] ? [] : ['settingHostsAgreed' => $history]),
        ];
    }

    /**
     * The hosts its inserted content may load from, kept with the consent so
     * a later version that adds one waits for another yes; nothing for a
     * plugin that inserts nothing or declares none
     * (docs/plans/plugin-sockets-2.md, B3).
     *
     * @param  list<string>  $capabilities
     *
     * @return array{}|array{csp: array<string, list<string>>}
     */
    private function insertionHosts(Manifest $manifest, array $capabilities): array
    {
        if (! in_array(Capability::ContentInsert->value, $capabilities, true) && ! in_array(Capability::PagesInsert->value, $capabilities, true)) {
            return [];
        }

        $declared = Consent::declaredInsertionHosts($manifest);

        return $declared === [] ? [] : ['csp' => $declared];
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function sameSet(array $a, array $b): bool
    {
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));
        sort($a);
        sort($b);

        return $a === $b;
    }
}

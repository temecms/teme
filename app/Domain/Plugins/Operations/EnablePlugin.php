<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Tables\PluginMigrations;
use App\Domain\Plugins\Tables\PluginTablesRefused;
use App\Domain\PublicRoutes\PublicRouteRegistry;
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

        if (! $same) {
            throw ValidationException::withMessages(['plugin' => ['It asks for something different now. Look again before you enable it.']]);
        }

        // Two owners never answer one request: refused before anything is written (docs/proposals/plugin-public-routes.md).
        $collisions = $this->routeCollisions->against($this->publicRoutes->declaredBy(PluginRecord::query()->where('state', PluginRecord::ENABLED)->orderBy('name')->get()->all()), $manifest);

        if ($collisions !== []) {
            throw ValidationException::withMessages(['plugin' => $collisions]);
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
            ],
            'consented_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            'consented_at' => $now,
            'enabled_at' => $now,
            // Enabling again is the manual clear of what Teme stopped.
            'faults' => [],
            'quarantine' => [],
            'migrations' => $migrations,
        ])->save();

        $this->log->record(SecurityEventType::PluginEnabled, $input->actorId(), context: [
            'plugin' => $input->name,
            'version' => $input->version,
            'capabilities' => $input->capabilities,
            'hosts' => $input->hosts,
        ]);
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'plugin:'.$code, ['plugin:'.$code]));

        // Article pages drawn before it was enabled are drawn again with its insertions, after commit (I-064).
        if (in_array(Capability::ContentInsert->value, $input->capabilities, true)) {
            app(InsertionsChanged::class)->notify();
        }

        DB::afterCommit(fn () => $this->records->changed());

        return $input->name;
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

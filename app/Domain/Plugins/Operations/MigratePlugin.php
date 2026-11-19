<?php

/**
 * Runs an enabled plugin's waiting migrations (I-3442): a person who holds
 * plugins.manage, after their password, or the operator. Consent must still
 * cover what it asks; a types file an update changed is applied with them.
 * Returns the migration names that ran, and the types file's path when it was applied.
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\ApplyPluginTypesInput;
use App\Domain\Plugins\Operations\Input\MigratePluginInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Tables\PluginMigrations;
use App\Domain\Plugins\Tables\PluginTablesRefused;
use App\Domain\Plugins\Types\PluginTypesFile;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\PublicRoutes\RouteCollisions;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class MigratePlugin extends BaseOperation implements RequiresElevation
{
    use AuthorizesPluginChanges;

    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginRecords $records,
        private readonly PluginMigrations $migrations,
        private readonly SecurityLog $log,
        private readonly PublicRouteRegistry $publicRoutes,
        private readonly RouteCollisions $routeCollisions,
        private readonly ApplyPluginTypes $types,
    ) {}

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void
    {
        /** @var MigratePluginInput $input */
        $found = $this->registry->find($input->name);

        if ($found === null) {
            throw ValidationException::withMessages(['plugin' => ["No plugin is named \"{$input->name}\"."]]);
        }

        $plugin = $this->registry->fresh($found);
        $manifest = $plugin->manifest;

        if ($plugin->problems !== [] || $manifest === null) {
            throw ValidationException::withMessages(['plugin' => [$plugin->problems[0] ?? 'The plugin has no teme-plugin.json.']]);
        }

        $record = PluginRecord::query()->where('name', $input->name)->where('state', PluginRecord::ENABLED)->first();

        if ($record === null) {
            throw ValidationException::withMessages(['plugin' => ['It isn’t enabled.']]);
        }

        if (! Consent::covers($record->consent, $manifest)) {
            throw ValidationException::withMessages(['plugin' => ['Look again before you run its changes.']]);
        }

        // An update's routes meet every other enabled plugin's, loading now or not (docs/proposals/plugin-public-routes.md).
        $collisions = $this->routeCollisions->against($this->publicRoutes->declaredBy(PluginRecord::query()->where('state', PluginRecord::ENABLED)->orderBy('name')->get()->all()), $manifest);

        if ($collisions !== []) {
            throw ValidationException::withMessages(['plugin' => $collisions]);
        }
    }

    /**
     * @return list<string>
     */
    protected function perform(OperationInput $input): array
    {
        /** @var MigratePluginInput $input */
        $record = PluginRecord::query()->where('name', $input->name)->lockForUpdate()->first();

        if ($record === null || $record->state !== PluginRecord::ENABLED) {
            throw ValidationException::withMessages(['plugin' => ['It isn’t enabled.']]);
        }

        $found = $this->registry->find($input->name) ?? throw ValidationException::withMessages(['plugin' => ["No plugin is named \"{$input->name}\"."]]);
        $before = array_keys($record->migrations);

        try {
            $migrations = $this->migrations->run($found, $record->migrations);
        } catch (PluginTablesRefused $refused) {
            throw ValidationException::withMessages(['plugin' => [$refused->getMessage()]]);
        }

        $ran = array_values(array_diff(array_keys($migrations), $before));
        $record->fill(['migrations' => $migrations])->save();

        // An update's types file, as its migrations (docs/architecture/plugins.md, *Content types from a plugin*).
        if (PluginTypesFile::waiting($this->registry->fresh($found), $record->types)) {
            $this->types->execute(new ApplyPluginTypesInput($input->name, $input->actorId()));
            $ran[] = $found->manifest === null ? 'types' : (PluginTypesFile::declared($found->manifest) ?? 'types');
        }

        if ($ran !== []) {
            $this->log->record(SecurityEventType::PluginMigrated, $input->actorId(), context: [
                'plugin' => $input->name,
                'migrations' => $ran,
            ]);
        }

        // Run after an update, so what the plugin's public routes stored may be another version's.
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'plugin:'.$record->code, ['plugin:'.$record->code]));

        DB::afterCommit(fn () => $this->records->changed());

        return $ran;
    }
}

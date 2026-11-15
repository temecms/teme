<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\MigratePluginInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Tables\PluginMigrations;
use App\Domain\Plugins\Tables\PluginTablesRefused;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Runs an enabled plugin's waiting migrations (I-3442): a person who holds
 * plugins.manage, after their password, or the operator. Consent must still
 * cover what it asks. Returns the migration names that ran.
 */
final class MigratePlugin extends BaseOperation implements RequiresElevation
{
    use AuthorizesPluginChanges;

    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginRecords $records,
        private readonly PluginMigrations $migrations,
        private readonly SecurityLog $log,
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

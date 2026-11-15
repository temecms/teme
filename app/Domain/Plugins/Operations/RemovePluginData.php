<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\RemovePluginDataInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Tables\PluginMigrations;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Drops a plugin's recorded tables (I-3442): a person who holds
 * plugins.manage, after their password, or the operator. The plugin must
 * be disabled, or its code gone. Works from the row alone, so a plugin
 * whose code is gone can still have its tables removed. Keeps the row and
 * its consent. Returns the tables dropped.
 */
final class RemovePluginData extends BaseOperation implements RequiresElevation
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
        /** @var RemovePluginDataInput $input */
        $record = PluginRecord::query()->where('name', $input->name)->first();

        if ($record === null) {
            throw ValidationException::withMessages(['plugin' => ["No plugin is named \"{$input->name}\"."]]);
        }

        if ($record->state === PluginRecord::ENABLED && ! $this->codeGone($input->name)) {
            throw ValidationException::withMessages(['plugin' => ['Disable it before you remove its data.']]);
        }

        if ($this->migrations->ownedTables($record->migrations) === []) {
            throw ValidationException::withMessages(['plugin' => ['It has no tables to remove.']]);
        }
    }

    /**
     * @return list<string>
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RemovePluginDataInput $input */
        $record = PluginRecord::query()->where('name', $input->name)->lockForUpdate()->first();

        if ($record === null) {
            throw ValidationException::withMessages(['plugin' => ["No plugin is named \"{$input->name}\"."]]);
        }

        if ($record->state === PluginRecord::ENABLED && ! $this->codeGone($input->name)) {
            throw ValidationException::withMessages(['plugin' => ['Disable it before you remove its data.']]);
        }

        if ($this->migrations->ownedTables($record->migrations) === []) {
            throw ValidationException::withMessages(['plugin' => ['It has no tables to remove.']]);
        }

        // Only tables that still exist: a recorded name that is gone never blocks removal (T3).
        $tables = $this->migrations->existingOwnedTables($record->migrations);
        $prefix = str_replace('-', '_', $record->code).'_';

        foreach ($tables as $table) {
            if (! str_starts_with($table, $prefix)) {
                throw ValidationException::withMessages([
                    'plugin' => ["The table {$table} doesn’t start with its prefix \"{$prefix}\"."],
                ]);
            }
        }

        if ($tables !== []) {
            $grammar = DB::connection()->getQueryGrammar();
            $quoted = array_map(fn (string $table): string => $grammar->wrapTable($table), $tables);
            DB::statement('DROP TABLE '.implode(', ', $quoted));
        }

        $record->fill(['migrations' => []])->save();

        $this->log->record(SecurityEventType::PluginDataRemoved, $input->actorId(), context: [
            'plugin' => $input->name,
            'tables' => $tables,
        ]);
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'plugin:'.$record->code, ['plugin:'.$record->code]));

        DB::afterCommit(fn () => $this->records->changed());

        return $tables;
    }

    /**
     * Whether the plugin's code is no longer installed, so its tables may
     * be removed even while the row still says enabled.
     */
    private function codeGone(string $name): bool
    {
        $found = $this->registry->find($name);

        return $found === null || $found->manifest === null;
    }
}

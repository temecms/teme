<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Tables;

use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a plugin's migrations that haven't run, in name order, inside the
 * caller's transaction (PostgreSQL changes tables transactionally), and
 * checks what each created and dropped against the plugin's manifest.
 * Returns the row's new record. Throws PluginTablesRefused, which undoes
 * the whole run with the caller's transaction.
 *
 * Teme can't see a migration change one of Teme's own tables: plugins are
 * trusted code (docs/architecture/plugins.md › Trust).
 */
final readonly class PluginMigrations
{
    public function __construct(private PluginRegistry $registry) {}

    /**
     * Runs waiting migrations for this plugin and returns the updated record.
     *
     * @param  array<string, array{version?: string, ran_at?: string, tables?: list<string>}>  $record
     *
     * @return array<string, array{version: string, ran_at: string, tables: list<string>}>
     */
    public function run(FoundPlugin $plugin, array $record): array
    {
        foreach ($this->waiting($plugin, $record) as $name => $file) {
            try {
                $migration = (static fn (string $path): mixed => require $path)($file);

                if (! $migration instanceof Migration) {
                    throw PluginTablesRefused::notAMigration($plugin, $name);
                }

                if ($migration->withinTransaction === false) {
                    throw PluginTablesRefused::outsideTransaction($plugin, $name);
                }

                if (! is_callable([$migration, 'up'])) {
                    throw PluginTablesRefused::notAMigration($plugin, $name);
                }

                $before = $this->tables();
                $migration->{'up'}();
                $after = $this->tables();
            } catch (PluginTablesRefused $refused) {
                throw $refused;
            } catch (Throwable $e) {
                report($e);
                throw PluginTablesRefused::failed($plugin, $name, $e);
            }

            $created = array_values(array_diff($after, $before));
            $dropped = array_values(array_diff($before, $after));

            $this->check($plugin, $record, $name, $created, $dropped);
            $record = $this->forgetDropped($record, $dropped);
            $record[$name] = [
                'version' => $plugin->manifest?->version() ?? '',
                'ran_at' => now()->toIso8601String(),
                'tables' => $created,
            ];
        }

        return $record;
    }

    /**
     * Migration names waiting to run, name => absolute path, in name order.
     *
     * @param  array<string, mixed>  $record
     *
     * @return array<string, string>
     */
    public function waiting(FoundPlugin $plugin, array $record): array
    {
        $waiting = [];

        foreach ($plugin->migrations as $name) {
            if (! array_key_exists($name, $record)) {
                $waiting[$name] = $plugin->path.'/database/migrations/'.$name.'.php';
            }
        }

        return $waiting;
    }

    /**
     * Every table this plugin's record still lists as created.
     *
     * @param  array<string, array{tables?: list<string>}>  $record
     *
     * @return list<string>
     */
    public function ownedTables(array $record): array
    {
        $tables = [];

        foreach ($record as $entry) {
            foreach ($entry['tables'] ?? [] as $table) {
                $tables[$table] = $table;
            }
        }

        return array_values($tables);
    }

    /**
     * Recorded tables that still exist in the current schema (plan T3).
     *
     * @param  array<string, array{tables?: list<string>}>  $record
     *
     * @return list<string>
     */
    public function existingOwnedTables(array $record): array
    {
        $owned = $this->ownedTables($record);

        if ($owned === []) {
            return [];
        }

        $present = array_flip($this->tables());

        return array_values(array_filter($owned, fn (string $table): bool => isset($present[$table])));
    }

    /**
     * The tables in Teme's schema now, by name.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return DB::table('pg_tables')->where('schemaname', DB::raw('current_schema()'))->pluck('tablename')->all();
    }

    /**
     * @param  array<string, array{tables?: list<string>}>  $record
     * @param  list<string>  $created
     * @param  list<string>  $dropped
     */
    private function check(FoundPlugin $plugin, array $record, string $name, array $created, array $dropped): void
    {
        $prefix = str_replace('-', '_', $plugin->code).'_';
        $listed = $plugin->manifest?->ownsTables() ?? [];
        $mine = $this->ownedTables($record);
        $others = $this->otherPrefixes($plugin);
        $claimed = $this->tablesInOtherRecords($plugin->name);

        foreach ($created as $table) {
            if (! str_starts_with($table, $prefix)) {
                throw PluginTablesRefused::created($plugin, $name, $table, "doesn’t start with its prefix \"{$prefix}\".");
            }

            if (! in_array($table, $listed, true)) {
                throw PluginTablesRefused::created($plugin, $name, $table, 'isn’t one it lists under owns.tables.');
            }

            if ($this->belongsToAnother($table, $prefix, $others) || in_array($table, $claimed, true)) {
                throw PluginTablesRefused::created($plugin, $name, $table, 'belongs to another plugin.');
            }
        }

        foreach ($dropped as $table) {
            if (! in_array($table, $mine, true)) {
                throw PluginTablesRefused::dropped($plugin, $name, $table);
            }
        }
    }

    /**
     * @param  array<string, array{tables?: list<string>}>  $record
     * @param  list<string>  $dropped
     *
     * @return array<string, array{version?: string, ran_at?: string, tables?: list<string>}>
     */
    private function forgetDropped(array $record, array $dropped): array
    {
        if ($dropped === []) {
            return $record;
        }

        foreach ($record as $name => $entry) {
            $tables = array_values(array_diff($entry['tables'] ?? [], $dropped));
            $record[$name] = [...$entry, 'tables' => $tables];
        }

        return $record;
    }

    /**
     * Other installed plugins' prefixes, longest first.
     *
     * @return list<string>
     */
    private function otherPrefixes(FoundPlugin $plugin): array
    {
        $prefixes = [];

        foreach ($this->registry->all() as $other) {
            if ($other->name === $plugin->name || $other->code === $plugin->code) {
                continue;
            }

            $prefixes[] = str_replace('-', '_', $other->code).'_';
        }

        usort($prefixes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $prefixes;
    }

    /**
     * @param  list<string>  $others
     */
    private function belongsToAnother(string $table, string $ownPrefix, array $others): bool
    {
        $best = $ownPrefix;

        foreach ($others as $prefix) {
            if (str_starts_with($table, $prefix) && strlen($prefix) > strlen($best)) {
                $best = $prefix;
            }
        }

        return $best !== $ownPrefix;
    }

    /**
     * Tables listed in every other plugin row's migrations record.
     *
     * @return list<string>
     */
    private function tablesInOtherRecords(string $name): array
    {
        $claimed = [];

        foreach (PluginRecord::query()->where('name', '!=', $name)->get(['migrations']) as $row) {
            foreach ($this->ownedTables($row->migrations) as $table) {
                $claimed[] = $table;
            }
        }

        return $claimed;
    }
}

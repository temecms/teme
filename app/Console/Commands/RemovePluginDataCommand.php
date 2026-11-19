<?php

/**
 * `teme:plugins:remove-data`: drops a plugin's recorded tables as the
 * operator (I-3442). Lists the tables and asks unless --force.
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Input\RemovePluginDataInput;
use App\Domain\Plugins\Operations\RemovePluginData;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Tables\PluginMigrations;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RemovePluginDataCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins:remove-data {name : The plugin’s Composer name} {--force : Drop without asking}';

    /** @var string */
    protected $description = 'Remove a disabled plugin’s database tables';

    public function handle(PluginRegistry $registry, PluginMigrations $migrations, RemovePluginData $remove): int
    {
        $name = (string) $this->argument('name');
        $record = PluginRecord::query()->where('name', $name)->first();
        $label = $registry->find($name)?->manifest?->label() ?? $name;

        if ($record === null) {
            $this->components->error(__('No plugin is named ":name".', ['name' => $name]));

            return self::FAILURE;
        }

        $tables = $migrations->ownedTables($record->migrations);

        if ($tables === []) {
            $this->components->error(__(':label has no tables to remove.', ['label' => $label]));

            return self::FAILURE;
        }

        $this->line(__(':label’s tables:', ['label' => $label]));

        foreach ($tables as $table) {
            $rows = $this->approximateRows($table);
            $this->line($rows === null
                ? "  {$table}"
                : '  '.__(':table (about :count rows)', ['table' => $table, 'count' => $rows]));
        }

        if (! $this->option('force') && ! $this->confirm(__('Remove these tables and everything in them?'))) {
            $this->components->info(__('Nothing was removed.'));

            return self::SUCCESS;
        }

        $this->laravel->instance(Elevation::class, new OperatorElevation);

        try {
            $dropped = $remove->execute(new RemovePluginDataInput($name, null));
        } catch (ValidationException $refused) {
            $this->components->error((string) collect($refused->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->components->info(__('Removed :n tables from :label.', ['n' => count($dropped), 'label' => $label]));

        return self::SUCCESS;
    }

    private function approximateRows(string $table): ?int
    {
        $estimate = DB::table('pg_class')->where('relname', $table)->value('reltuples');

        if (! is_numeric($estimate) || (float) $estimate < 0) {
            return null;
        }

        return (int) round((float) $estimate);
    }
}

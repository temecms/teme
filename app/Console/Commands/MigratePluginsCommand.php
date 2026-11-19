<?php

/**
 * `teme:plugins:migrate`: runs waiting migrations for one enabled plugin, or
 * every enabled plugin that has them, as the operator (I-3442).
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Input\MigratePluginInput;
use App\Domain\Plugins\Operations\MigratePlugin;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Tables\PluginMigrations;
use App\Domain\Plugins\Types\PluginTypesFile;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class MigratePluginsCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins:migrate {name? : The plugin’s Composer name}';

    /** @var string */
    protected $description = 'Run waiting database changes for enabled plugins';

    public function handle(PluginRegistry $registry, PluginMigrations $migrations, MigratePlugin $migrate): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $names = $this->argument('name') !== null
            ? [(string) $this->argument('name')]
            : $this->waitingNames($registry, $migrations);

        if ($names === []) {
            $this->components->info(__('No enabled plugin has database changes waiting.'));

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($names as $name) {
            $label = $registry->find($name)?->manifest?->label() ?? $name;

            try {
                $ran = $migrate->execute(new MigratePluginInput($name, null));
            } catch (ValidationException $refused) {
                $this->components->error((string) collect($refused->errors())->flatten()->first());
                $failed = true;

                continue;
            }

            if ($ran === []) {
                $this->line(__(':label: nothing waiting.', ['label' => $label]));

                continue;
            }

            foreach ($ran as $migration) {
                $this->line("{$label}: {$migration}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function waitingNames(PluginRegistry $registry, PluginMigrations $migrations): array
    {
        $names = [];

        foreach (PluginRecord::query()->where('state', PluginRecord::ENABLED)->orderBy('name')->get() as $record) {
            $plugin = $registry->find($record->name);

            if ($plugin === null || $plugin->manifest === null || ! Consent::covers($record->consent, $plugin->manifest)) {
                continue;
            }

            if ($migrations->waiting($plugin, $record->migrations) !== [] || PluginTypesFile::waiting($plugin, $record->types)) {
                $names[] = $record->name;
            }
        }

        return $names;
    }
}

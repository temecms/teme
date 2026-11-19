<?php

/**
 * `teme:plugins:disable`: disables a plugin as the operator (I-3353),
 * keeping its row and what was agreed to.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

final class DisablePluginCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins:disable {name : The plugin’s Composer name}';

    /** @var string */
    protected $description = 'Disable a plugin';

    public function handle(PluginRegistry $registry, DisablePlugin $disable): int
    {
        $name = (string) $this->argument('name');
        $record = PluginRecord::query()->where('name', $name)->first();
        $plugin = $registry->find($name);

        if ($record === null && $plugin === null) {
            $this->components->error(__('No plugin is named ":name".', ['name' => $name]));

            return self::FAILURE;
        }

        $label = $plugin?->manifest?->label() ?? $name;

        if ($record?->state !== PluginRecord::ENABLED) {
            $this->components->error(__(':label is already disabled.', ['label' => $label]));

            return self::FAILURE;
        }

        // The operator (docs/architecture/operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $disable->execute(new DisablePluginInput($name, null));

        $this->components->info(__(':label disabled.', ['label' => $label]));

        return self::SUCCESS;
    }
}

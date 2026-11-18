<?php

/**
 * `teme:plugins:forget`: deletes a disabled plugin's record when its
 * package is gone, as the operator, so its code is free again.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\ForgetPlugin;
use App\Domain\Plugins\Operations\Input\ForgetPluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ForgetPluginCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins:forget {name : The plugin’s Composer name} {--force : Forget without asking}';

    /** @var string */
    protected $description = 'Forget a disabled plugin whose package is no longer installed';

    public function handle(PluginRegistry $registry, ForgetPlugin $forget): int
    {
        $name = (string) $this->argument('name');
        $record = PluginRecord::query()->where('name', $name)->first();
        $label = $registry->find($name)?->manifest?->label() ?? $name;

        if ($record === null) {
            $this->components->error(__('No plugin is named ":name".', ['name' => $name]));

            return self::FAILURE;
        }

        $this->line(__('Forget :label’s record? Its code “:code” will be free for another package.', [
            'label' => $label,
            'code' => $record->code,
        ]));

        if (! $this->option('force') && ! $this->confirm(__('Forget this plugin’s record?'))) {
            $this->components->info(__('Nothing was forgotten.'));

            return self::SUCCESS;
        }

        $this->laravel->instance(Elevation::class, new OperatorElevation);

        try {
            $forget->execute(new ForgetPluginInput($name, null));
        } catch (ValidationException $refused) {
            $this->components->error((string) collect($refused->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->components->info(__('Forgot :label.', ['label' => $label]));

        return self::SUCCESS;
    }
}

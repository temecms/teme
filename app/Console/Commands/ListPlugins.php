<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\Queries\PluginRow;
use Illuminate\Console\Command;

/**
 * `teme:plugins`: every plugin with its version, where it stands and why,
 * for whoever runs the server (I-3353).
 */
final class ListPlugins extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins';

    /** @var string */
    protected $description = 'List the plugins, with their version, state and why';

    public function handle(PluginList $plugins): int
    {
        $rows = $plugins->all();

        if ($rows === []) {
            $this->components->info(__('No plugins are installed.'));

            return self::SUCCESS;
        }

        $this->table(
            [__('Plugin'), __('Version'), __('State'), __('Why')],
            array_map(fn (PluginRow $row): array => [$row->name, $row->version, $row->state->label(), $row->reason], $rows),
        );

        return self::SUCCESS;
    }
}

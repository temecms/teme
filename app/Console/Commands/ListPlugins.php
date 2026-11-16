<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\Dependencies;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\Queries\PluginRow;
use Illuminate\Console\Command;

/**
 * `teme:plugins`: every plugin with its version, where it stands and why,
 * and the plugins it requires and is required by, for whoever runs the
 * server (I-3353).
 */
final class ListPlugins extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins';

    /** @var string */
    protected $description = 'List the plugins, with their version, state and why';

    public function handle(PluginList $plugins, PluginRegistry $registry): int
    {
        $rows = $plugins->all();

        if ($rows === []) {
            $this->components->info(__('No plugins are installed.'));

            return self::SUCCESS;
        }

        $this->table(
            [__('Plugin'), __('Version'), __('State'), __('Why'), __('Requires'), __('Required by')],
            array_map(fn (PluginRow $row): array => [$row->name, $row->version, $row->state->label(), $row->reason, $this->requires($registry, $row->name), implode(', ', $row->requiredBy)], $rows),
        );

        return self::SUCCESS;
    }

    /**
     * The plugins this one requires, each with the versions it accepts.
     */
    private function requires(PluginRegistry $registry, string $name): string
    {
        $manifest = $registry->find($name)?->manifest;
        $required = $manifest === null ? [] : Dependencies::required($manifest);

        return implode(', ', array_map(fn (string $plugin, string $constraint): string => $plugin.' '.$constraint, array_keys($required), $required));
    }
}

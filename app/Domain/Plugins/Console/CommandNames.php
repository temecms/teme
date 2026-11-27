<?php

/**
 * Which of a plugin's declared command names are already taken: by a
 * command of Teme's or Laravel's, or by another enabled plugin. A plugin may
 * never replace a command that exists.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Console;

use App\Domain\Plugins\Manifest;
use Illuminate\Contracts\Console\Kernel;

final class CommandNames
{
    /**
     * Returns the plugin's declared names that a core command or one of the
     * other plugins' declared commands already uses, in declared order.
     *
     * @param  list<Manifest>  $others  the other enabled plugins' manifests
     *
     * @return list<string>
     */
    public static function taken(Manifest $manifest, array $others): array
    {
        $declared = array_column(DeclaredCommands::of($manifest), 'name');

        if ($declared === []) {
            return [];
        }

        $used = [];

        foreach ($others as $other) {
            foreach (DeclaredCommands::of($other) as $command) {
                $used[$command['name']] = true;
            }
        }

        // A plugin's own command, started in this process, isn't a clash with itself.
        foreach (app(Kernel::class)->all() as $name => $command) {
            if (! $command instanceof PluginCommand) {
                $used[$name] = true;
            }
        }

        return array_values(array_filter($declared, fn (string $name): bool => isset($used[$name])));
    }
}

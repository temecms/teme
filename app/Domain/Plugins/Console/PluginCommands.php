<?php

/**
 * The console commands of the plugins that started in this process, kept
 * until the console application is built, which adds them: a plugin that
 * didn't start has none, so a disabled plugin's command doesn't exist.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Console;

use App\Domain\Hooks\ListenerFaults;
use Illuminate\Console\Application;
use RuntimeException;

final class PluginCommands
{
    /** @var list<PluginCommand> */
    private array $commands = [];

    /**
     * Keeps a started plugin's declared command.
     *
     * @param  string  $name  the plugin's name, by which its running instance is found
     * @param  array{name: string, description: string, arguments: list<array{name: string, description: string, required: bool}>, options: list<array{name: string, description: string, value: bool}>}  $declared
     */
    public function add(string $name, array $declared): void
    {
        $this->commands[] = new PluginCommand($name, $declared);
    }

    /**
     * Adds every kept command to the console application.
     */
    public function addTo(Application $artisan): void
    {
        foreach ($this->commands as $command) {
            // add() replaces a command of the same name; a plugin never replaces one.
            if ($artisan->has((string) $command->getName())) {
                app(ListenerFaults::class)->record($command->owner(), $command->owner().'#command', '', new RuntimeException("The command {$command->getName()} already exists, so the plugin’s was not added."));

                continue;
            }

            $artisan->add($command);
        }
    }

    /**
     * The names of the commands kept, in the order their plugins started.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(fn (PluginCommand $command): string => (string) $command->getName(), $this->commands);
    }
}

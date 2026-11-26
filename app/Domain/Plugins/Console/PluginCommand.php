<?php

/**
 * One console command a plugin declared: parses its arguments and options as
 * the manifest declares them and hands them, as plain arrays, to the plugin
 * that started, run as the operator.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Console;

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as Status;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Teme\Contracts\Console\HasCommands;
use Throwable;

final class PluginCommand extends Command
{
    /**
     * @param  string  $owner  the plugin's name, by which its running instance is found
     * @param  array{name: string, description: string, arguments: list<array{name: string, description: string, required: bool}>, options: list<array{name: string, description: string, value: bool}>}  $declared
     */
    public function __construct(private readonly string $owner, private readonly array $declared)
    {
        $this->name = $declared['name'];
        $this->description = $declared['description'];

        parent::__construct();

        foreach ($declared['arguments'] as $argument) {
            $this->addArgument($argument['name'], $argument['required'] ? InputArgument::REQUIRED : InputArgument::OPTIONAL, $argument['description']);
        }

        foreach ($declared['options'] as $option) {
            $this->addOption($option['name'], null, $option['value'] ? InputOption::VALUE_REQUIRED : InputOption::VALUE_NONE, $option['description']);
        }
    }

    /**
     * Runs the plugin's answer for this command, as the operator at the shell.
     */
    public function handle(LoadedPlugins $loaded): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $plugin = $loaded->running()[$this->owner] ?? null;

        if (! $plugin instanceof HasCommands) {
            $this->components->error(__('The plugin that adds :name isn’t running.', ['name' => $this->declared['name']]));

            return Status::FAILURE;
        }

        $arguments = [];
        $options = [];

        foreach ($this->declared['arguments'] as $argument) {
            $value = $this->argument($argument['name']);
            $arguments[$argument['name']] = is_string($value) ? $value : null;
        }

        foreach ($this->declared['options'] as $option) {
            $value = $this->option($option['name']);
            $options[$option['name']] = $option['value'] ? (is_string($value) ? $value : null) : $value === true;
        }

        try {
            return $plugin->runCommand($this->declared['name'], $arguments, $options, new CommandLines($this->output));
        } catch (Throwable $failure) {
            report($failure);
            $this->components->error(__('The command :name failed: :error', ['name' => $this->declared['name'], 'error' => strtok($failure->getMessage(), "\n") ?: $failure::class]));

            return Status::FAILURE;
        }
    }
}

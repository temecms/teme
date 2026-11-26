<?php

/**
 * A plugin with console commands: it runs the ones its manifest declares.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Console;

/**
 * A plugin with console commands: it runs the ones its manifest declares
 * under `contributes.commands`. Implemented beside Plugin. Teme lists a
 * command in `php artisan` only while the plugin is enabled, and runs it
 * with the plugin's own doors, as the operator at the server's shell.
 *
 * @since next
 */
interface HasCommands
{
    /**
     * Runs one declared command by its name, such as `sharely:resend`.
     *
     * @param  string  $name  the command's declared name
     * @param  array<string, string|null>  $arguments  each declared argument by its name; null for an optional one not given
     * @param  array<string, string|bool|null>  $options  each declared option by its name: true or false for a flag, the text given or null for one that takes a value
     * @param  CommandOutput  $output  where to write what the person at the terminal reads
     *
     * @return int the exit code: 0 for success, any other number for failure
     *
     * @since next
     */
    public function runCommand(string $name, array $arguments, array $options, CommandOutput $output): int;
}

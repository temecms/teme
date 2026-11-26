<?php

/**
 * Where a plugin's console command writes: lines, with no framework type in
 * the way.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Console;

/**
 * Where a plugin's console command writes its lines. Teme gives one to
 * HasCommands::runCommand() and shows what is written in the terminal.
 *
 * @since next
 */
interface CommandOutput
{
    /**
     * Writes a plain line.
     *
     * @since next
     */
    public function line(string $text): void;

    /**
     * Writes a line that says something went as it should.
     *
     * @since next
     */
    public function info(string $text): void;

    /**
     * Writes a line that says something needs attention but didn't stop the command.
     *
     * @since next
     */
    public function warning(string $text): void;

    /**
     * Writes a line that says something failed.
     *
     * @since next
     */
    public function error(string $text): void;
}

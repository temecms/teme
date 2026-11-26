<?php

/**
 * What a plugin's command writes, shown in the terminal: the contract's
 * output interface over the console's.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Console;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Teme\Contracts\Console\CommandOutput;

final readonly class CommandLines implements CommandOutput
{
    public function __construct(private OutputStyle $output) {}

    public function line(string $text): void
    {
        $this->output->writeln(self::plain($text));
    }

    public function info(string $text): void
    {
        $this->output->writeln('<info>'.self::plain($text).'</info>');
    }

    public function warning(string $text): void
    {
        $this->output->writeln('<comment>'.self::plain($text).'</comment>');
    }

    public function error(string $text): void
    {
        $this->output->writeln('<error>'.self::plain($text).'</error>');
    }

    /** The text with the console's own markup escaped, so what a plugin writes is shown as written. */
    private static function plain(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}

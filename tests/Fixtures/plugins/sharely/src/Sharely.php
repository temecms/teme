<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Sharely;

use RuntimeException;
use Teme\Contracts\Console\CommandOutput;
use Teme\Contracts\Console\HasCommands;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin with console commands. The tests read what it was asked.
 */
final class Sharely implements HasCommands, Plugin
{
    /** @var list<array{string, array<string, string|null>, array<string, string|bool|null>}> */
    public static array $asked = [];

    public static function reset(): void
    {
        self::$asked = [];
    }

    public function register(Hooks $hooks): void {}

    public function runCommand(string $name, array $arguments, array $options, CommandOutput $output): int
    {
        self::$asked[] = [$name, $arguments, $options];

        if ($name === 'sharely:fail') {
            throw new RuntimeException("It broke\nsecond line");
        }

        $output->line('Resending <b>shares</b>');
        $output->info('Resent '.($options['limit'] ?? 'all'));
        $output->warning('Skipped '.($arguments['service'] ?? 'none'));
        $output->error('Nothing failed really');

        return $options['dry-run'] === true ? 2 : 0;
    }
}

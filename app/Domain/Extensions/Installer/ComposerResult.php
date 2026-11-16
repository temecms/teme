<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Installer;

/**
 * How one Composer run ended: its exit code and what it printed.
 */
final readonly class ComposerResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}

    /** Whether Composer succeeded. */
    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** Returns Composer's last lines that say something, for the request's reason. */
    public function lastLines(int $count = 12): string
    {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", str_replace("\r", '', $this->output))), fn (string $line): bool => trim($line) !== ''));

        return implode("\n", array_slice($lines, -$count));
    }
}

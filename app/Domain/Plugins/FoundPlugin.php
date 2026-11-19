<?php

/**
 * One plugin found on disk: its Composer name, its code, its folder, its
 * manifest (null when teme-plugin.json couldn't be read at all), its
 * migration names, and every problem found in it. A plugin with any
 * problem can't be used, and is listed with its problems.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

final readonly class FoundPlugin
{
    /**
     * @param  list<string>  $problems
     * @param  list<string>  $migrations  migration file names without .php, in name order
     */
    public function __construct(
        public string $name,
        public string $code,
        public string $path,
        public ?Manifest $manifest,
        public array $problems,
        public array $migrations = [],
    ) {}

    /**
     * Whether the plugin may be enabled and started: it has no problem.
     */
    public function usable(): bool
    {
        return $this->manifest !== null && $this->problems === [];
    }
}

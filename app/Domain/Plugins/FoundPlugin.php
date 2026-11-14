<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

/**
 * One plugin found on disk: its Composer name, its code, its folder, its
 * manifest (null when teme-plugin.json couldn't be read at all) and every
 * problem found in it. A plugin with any problem can't be used, and is
 * listed with its problems.
 */
final readonly class FoundPlugin
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public string $name,
        public string $code,
        public string $path,
        public ?Manifest $manifest,
        public array $problems,
    ) {}

    /**
     * Whether the plugin may be enabled and started: it has no problem.
     */
    public function usable(): bool
    {
        return $this->manifest !== null && $this->problems === [];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Seams;

/**
 * Registers what a started plugin's manifest contributes to the admin
 * (I-3353): Contributions does.
 */
interface PluginContributions
{
    /**
     * Registers what a started plugin's manifest contributes, all or
     * nothing; returns why it can't, or [].
     *
     * @param  array<string, mixed>  $contributes
     * @param  list<mixed>  $jobs  the manifest's top-level jobs list (I-3445)
     *
     * @return list<string>
     */
    public function add(string $name, string $code, string $label, string $version, array $contributes, object $plugin, array $jobs = []): array;
}

<?php

/**
 * Checks what a plugin's manifest adds to Teme's lists (`contributes`), as
 * part of the manifest's validation: ContributionsValidator does.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seams;

interface ContributionsCheck
{
    /**
     * Why the plugin's contributions can't be used, or [] when they can.
     *
     * @param  array<string, mixed>  $contributes
     * @param  list<string>|null  $capabilities  what the manifest asks for, which some surfaces need; null where that was checked already
     *
     * @return list<string>
     */
    public function errors(array $contributes, string $version, ?array $capabilities = null): array;
}

<?php

/**
 * The service's compose file as the platform holds it: its text, and whether the platform takes it from Git,
 * where the platform's own pull brings each new file and the updater leaves it alone.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

final readonly class PlatformCompose
{
    public function __construct(
        public string $text,
        public bool $fromGit,
    ) {}
}

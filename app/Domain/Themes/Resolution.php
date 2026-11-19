<?php

/**
 * The template chosen for a page: the view that renders it, every candidate
 * tried in order with whether it exists, and whether core's fallback views
 * drew it instead of a theme.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

final readonly class Resolution
{
    /**
     * @param  list<array{name: string, exists: bool}>  $candidates
     */
    public function __construct(
        public string $view,
        public array $candidates,
        public bool $fallback,
    ) {}
}

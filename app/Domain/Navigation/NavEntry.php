<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

/**
 * One entry in the admin's main menu: where it sits, where it links — a
 * route, or a hub that finds the first page the person may open — and the
 * ability it needs, the module it belongs to, and its badge's provider.
 */
final readonly class NavEntry
{
    /**
     * @param  array<string, string>  $routeParameters
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public NavSection $section,
        public int $order,
        public ?string $route = null,
        public array $routeParameters = [],
        public ?string $ability = null,
        public ?string $parent = null,
        public ?string $hub = null,
        public string $owner = 'core',
        /** The module this entry belongs to: left out while it's switched off (I-077). */
        public ?string $module = null,
        /** A container key, usually a class, that resolves to the BadgeProvider counting what waits here. */
        public ?string $badge = null,
    ) {}
}

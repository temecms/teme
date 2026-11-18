<?php

/**
 * Core's composer for menus, run on every theme view after SiteComposer:
 * `navigation`, each of the active theme's slots with its menu's tree, or
 * null when it has none, for a theme that draws its own markup rather than
 * `<x-teme::navigation>`. The trees are SlotMenus', built once per page.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus;

use Teme\Contracts\Themes\ViewComposer;
use Teme\Contracts\Themes\ViewContext;

final readonly class NavigationComposer implements ViewComposer
{
    public function __construct(private SlotMenus $menus) {}

    public function views(): array
    {
        return ['*'];
    }

    public function with(ViewContext $context): array
    {
        return ['navigation' => array_map(fn (array $slot): ?array => $slot['tree'], $this->menus->all())];
    }
}

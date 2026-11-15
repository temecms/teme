<?php

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\Themes\ActiveTheme;

/**
 * Says when a place the active theme draws a menu in has none, so visitors
 * see nothing there (study B, decision 2), naming each place by its label.
 * It reads only the active theme's manifest and its one cached settings
 * group: every admin page asks every check.
 */
final readonly class NavigationSlotsBound implements StatusCheck
{
    public function __construct(
        private ActiveTheme $active,
        private ThemeSlotSettings $slots,
    ) {}

    public function ability(): string
    {
        return 'navigation.manage';
    }

    public function label(): string
    {
        return __('Navigation');
    }

    public function banner(): ?StatusBanner
    {
        $theme = $this->active->get();
        $labels = $theme?->manifest?->navigations() ?? [];

        if ($theme === null || $labels === []) {
            return null;
        }

        $unbound = [];

        foreach ($this->slots->bound($theme) as $slot => $navigationId) {
            if ($navigationId === null) {
                $unbound[] = '"'.$labels[$slot].'"';
            }
        }

        if ($unbound === []) {
            return null;
        }

        $places = count($unbound) === 1 ? $unbound[0] : implode(', ', array_slice($unbound, 0, -1)).' and '.end($unbound);

        return new StatusBanner(
            'warning',
            "No menu is chosen for {$places}, so visitors see nothing there.",
            'Choose menus',
            route('navigation.index'),
            // Below the theme check (100), above the search rebuild (10).
            50,
        );
    }
}

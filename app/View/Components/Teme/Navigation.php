<?php

/**
 * `<x-teme::navigation slot="primary" label="Main" />`: the menu a theme's
 * slot shows, as a labelled landmark with `aria-current` on the page being
 * shown.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Menus\SlotMenus;
use App\Domain\Themes\ComponentOverrides;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-teme::navigation slot="primary" label="Main" />`: the menu a theme's
 * slot shows, as a labelled landmark with `aria-current` on the page being
 * shown. A slot with no menu is loud outside production and silent
 * in it. It reads the slot from SlotMenus, never the
 * template's variables. Its view gets the slot as `$slotKey`: there, `$slot`
 * is always Blade's default slot. A heading is drawn as a button that
 * opens the list of its items.
 */
final class Navigation extends Component
{
    /** The contract a theme's override of this component is written against (docs/guides/developers/themes/components.md). */
    public const int CONTRACT = 1;

    public string $slotKey;

    /** The slot's label in the theme's manifest, such as "Header". */
    public string $slotLabel;

    /** The landmark's name for screen readers. */
    public string $label;

    /** The name this drawing's heading lists build their ids from, unique on the page. */
    public string $menuId;

    /** @var list<array{label: string, url: string|null, current: bool, opensNewTab: bool, heading: bool, children: list<mixed>}>|null */
    public ?array $items;

    public bool $showUnbound;

    /**
     * @param  string  $slot  the theme's navigation slot to draw, such as primary
     * @param  string|null  $label  the landmark's name for screen readers; the slot's label when null
     */
    public function __construct(SlotMenus $menus, string $slot, ?string $label = null)
    {
        $found = $menus->slot($slot);

        $this->slotKey = $slot;
        $this->menuId = $menus->nextId($slot);
        $this->slotLabel = $found['label'] ?? $slot;
        $this->label = $label ?? $this->slotLabel;
        $this->items = $found['tree'] ?? null;
        $this->showUnbound = ! app()->isProduction();
    }

    public function render(): View
    {
        return app(ComponentOverrides::class)->render('navigation', 'teme::components.navigation');
    }
}

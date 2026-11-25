<?php

/**
 * Where a just-enabled plugin's parts appear in the admin: fixed
 * translatable phrases from its contributions, for the enable toast.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Plugins\Area\AreaEntries;
use App\Domain\Plugins\Area\AreaWords;
use App\Domain\Plugins\Area\DeclaredArea;
use Illuminate\Support\Arr;

final readonly class PluginPlaces
{
    public function __construct(
        private PluginRegistry $registry,
        private AreaEntries $areas,
    ) {}

    /**
     * The places clause for the enable toast: where the plugin's parts sit,
     * or that it runs in the background when nothing opens for a person.
     */
    public function sentence(string $name): string
    {
        $found = $this->registry->find($name);
        $manifest = $found?->manifest;

        if ($manifest === null) {
            return __('it runs in the background; there is nothing to open');
        }

        $contributes = $manifest->contributes();
        $parts = [];

        $area = $contributes['area'] ?? null;

        if (is_array($area)) {
            $declared = DeclaredArea::from($area);
            $label = AreaWords::text($found->code, $declared->label);

            if ($this->areas->onTools($found->code)) {
                $parts[] = __('its area is on the Tools hub as :label', ['label' => $label]);
            } else {
                $section = $declared->menu === 'content' ? __('Content') : __('Site');
                $parts[] = __('its area is in the menu under :section › :label', [
                    'section' => $section,
                    'label' => $label,
                ]);
            }
        }

        $settings = $contributes['settings'] ?? null;

        if (is_array($settings) && $settings !== []) {
            $parts[] = is_array($area)
                ? __('its settings are on its page’s Settings tab')
                : __('its settings are in Settings › :plugin', ['plugin' => $manifest->label()]);
        }

        $panels = array_values(array_filter(
            is_array($contributes['editorPanels'] ?? null) ? $contributes['editorPanels'] : [],
            is_array(...),
        ));

        if ($panels !== []) {
            $labels = array_values(array_filter(array_map(
                fn (array $panel): string => is_string($panel['label'] ?? null) ? AreaWords::text($found->code, $panel['label']) : '',
                $panels,
            )));

            $parts[] = count($labels) === 1
                ? __('it adds a :label panel to the editor', ['label' => $labels[0]])
                : __('it adds panels to the editor');
        }

        $admin = array_values(array_filter(
            is_array($contributes['admin'] ?? null) ? $contributes['admin'] : [],
            is_array(...),
        ));

        if ($admin !== []) {
            $parts[] = __('it adds a card to Tools');
        }

        if ($parts === []) {
            return __('it runs in the background; there is nothing to open');
        }

        return Arr::join($parts, ', ', ' '.__('and').' ');
    }
}

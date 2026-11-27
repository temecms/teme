<?php

/**
 * The stylesheets a public page links for the plugin outputs it drew: each
 * recorded output whose enabled plugin declares a stylesheet for it under
 * contributes.styles, at the address `teme:plugins:link` serves it from.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Styles;

use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Themes\ActiveTheme;

final class PluginStyles
{
    public function __construct(
        private readonly PluginStyleUsage $usage,
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
        private readonly ActiveTheme $theme,
    ) {}

    /**
     * Each stylesheet to link, as its key (`code:output`) and its address,
     * in the order the outputs were drawn; none when nothing drawn has one.
     *
     * @return list<array{key: string, href: string}>
     */
    public function links(): array
    {
        $codes = [];

        foreach ($this->records->enabled() as $record) {
            $codes[(string) $record->code] = (string) $record->name;
        }

        $switchedOff = $this->theme->get()?->manifest?->pluginStylesOff() ?? [];
        $links = [];

        foreach ($this->usage->entries() as $entry) {
            $name = $codes[$entry['code']] ?? null;
            $manifest = $name === null ? null : ($this->registry->all()[$name]->manifest ?? null);

            if ($manifest === null || in_array($entry['code'].':'.$entry['output'], $switchedOff, true)) {
                continue;
            }

            foreach (DeclaredStyles::entries($manifest->contributes()) as $style) {
                if ($style['output'] === $entry['output']) {
                    $links[] = [
                        'key' => $entry['code'].':'.$entry['output'],
                        'href' => asset('plugins/'.$entry['code'].'/'.$style['file']),
                    ];
                }
            }
        }

        return $links;
    }
}

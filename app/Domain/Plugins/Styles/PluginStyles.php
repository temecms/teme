<?php

/**
 * The stylesheets a public page links for the plugin outputs it drew: each recorded
 * output whose enabled plugin declares a stylesheet for it under contributes.styles.
 * Core serves each sheet itself at its route, with the sheet's content hash as its
 * version, so a changed file gets a new address.
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
use Illuminate\Support\Facades\Cache;

final class PluginStyles
{
    public function __construct(
        private readonly PluginStyleUsage $usage,
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
        private readonly ActiveTheme $theme,
        private readonly PluginStylesheet $sheets,
    ) {}

    /**
     * Each stylesheet to link, as its key (`code:output`) and its address with its version,
     * in the order the outputs were drawn; none when nothing drawn has a sheet that can be served.
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
                if ($style['output'] !== $entry['output']) {
                    continue;
                }

                $hash = $this->servedHash($name, $manifest->version(), $entry['code'], $style['file']);

                if ($hash !== null) {
                    $links[] = [
                        'key' => $entry['code'].':'.$entry['output'],
                        'href' => route('teme.plugins.styles', ['code' => $entry['code'], 'file' => $style['file']]).'?v='.$hash,
                    ];
                }
            }
        }

        return $links;
    }

    /**
     * The hash of a declared sheet Teme serves, or null when it is refused. Cached per plugin,
     * version and file, since a version's package doesn't change.
     */
    private function servedHash(string $name, string $version, string $code, string $file): ?string
    {
        $hash = Cache::rememberForever('plugin-styles-link:'.$name.':'.$version.':'.$file, fn (): string => (string) $this->sheets->hash($code, $file));

        return $hash === '' ? null : $hash;
    }
}

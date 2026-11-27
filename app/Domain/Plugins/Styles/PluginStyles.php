<?php

/**
 * The stylesheets a public page links for the plugin outputs it drew: each
 * recorded output whose enabled plugin declares a stylesheet for it under
 * contributes.styles and whose copy `teme:plugins:link` wrote, at the address
 * it is served from, with the copy's content hash as its version.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Styles;

use App\Console\Commands\LinkPluginStyles;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Themes\ActiveTheme;

final class PluginStyles
{
    /** @var array<string, array<string, string>>|null the copies `teme:plugins:link` wrote, by code and file */
    private ?array $copies = null;

    public function __construct(
        private readonly PluginStyleUsage $usage,
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
        private readonly ActiveTheme $theme,
    ) {}

    /**
     * Each stylesheet to link, as its key (`code:output`) and its address with
     * its version, in the order the outputs were drawn; none when nothing drawn
     * has one that was copied.
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
                $hash = $this->copies()[$entry['code']][$style['file']] ?? null;

                if ($style['output'] === $entry['output'] && $hash !== null) {
                    $links[] = [
                        'key' => $entry['code'].':'.$entry['output'],
                        'href' => asset('plugins/'.$entry['code'].'/'.$style['file']).'?v='.$hash,
                    ];
                }
            }
        }

        return $links;
    }

    /**
     * The copies `teme:plugins:link` wrote, by code and file, each with its hash; none
     * before the command has run. Read once per request.
     *
     * @return array<string, array<string, string>>
     */
    private function copies(): array
    {
        if ($this->copies !== null) {
            return $this->copies;
        }

        $path = public_path('plugins/'.LinkPluginStyles::MANIFEST);
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return $this->copies = is_array($manifest) ? $manifest : [];
    }
}

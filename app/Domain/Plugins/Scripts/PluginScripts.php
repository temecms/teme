<?php

/**
 * The scripts a public page links for the plugin outputs it drew: each recorded output whose enabled
 * plugin has the site.scripts capability and declares a script for it under contributes.scripts, and
 * whose active theme doesn't switch it off. Core serves each script at its own route with the script's
 * content hash as its version. A plugin whose scripts pass 20 KB compressed on one page is recorded as
 * a fault once per version, never blocked.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Scripts;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Styles\PluginStyleUsage;
use App\Domain\Themes\ActiveTheme;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Teme\Contracts\Plugins\Capability;

final class PluginScripts
{
    /** The compressed size, in bytes, a plugin's scripts on one page may reach before a fault is recorded. */
    public const int BUDGET = 20 * 1024;

    public function __construct(
        private readonly PluginStyleUsage $usage,
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
        private readonly ActiveTheme $theme,
        private readonly PluginScriptFile $files,
        private readonly ListenerFaults $faults,
    ) {}

    /**
     * Each script to link, as its key (`code:output`) and its address with its version, in the order the
     * outputs were drawn; none when nothing drawn has a script that can be served.
     *
     * @return list<array{key: string, href: string}>
     */
    public function links(): array
    {
        $grants = [];
        $codes = [];

        foreach ($this->records->enabled() as $record) {
            $codes[(string) $record->code] = (string) $record->name;
            $grants[(string) $record->code] = array_values(array_filter((array) ($record->consent['capabilities'] ?? []), is_string(...)));
        }

        $switchedOff = $this->theme->get()?->manifest?->pluginScriptsOff() ?? [];
        $links = [];
        $sizes = [];
        $versions = [];

        foreach ($this->usage->entries() as $entry) {
            $name = $codes[$entry['code']] ?? null;
            $manifest = $name === null ? null : ($this->registry->all()[$name]->manifest ?? null);

            if ($manifest === null
                || ! in_array(Capability::SiteScripts->value, $grants[$entry['code']] ?? [], true)
                || in_array($entry['code'].':'.$entry['output'], $switchedOff, true)) {
                continue;
            }

            foreach (DeclaredScripts::entries($manifest->contributes()) as $script) {
                if ($script['output'] !== $entry['output']) {
                    continue;
                }

                try {
                    $served = $this->files->read($entry['code'], $script['file']);
                } catch (InvalidArgumentException $refused) {
                    $this->faults->record($name, $entry['code'].'#scripts', self::class, $refused);

                    continue;
                }

                if ($served === null) {
                    continue;
                }

                $sizes[$name] = ($sizes[$name] ?? 0) + strlen((string) gzencode($served['text'], 9));
                $versions[$name] = $manifest->version();
                $links[] = [
                    'key' => $entry['code'].':'.$entry['output'],
                    'href' => route('teme.plugins.scripts', ['code' => $entry['code'], 'file' => $script['file']]).'?v='.$served['hash'],
                ];
            }
        }

        $this->budget($sizes, $versions);

        return $links;
    }

    /**
     * Records a fault, once per plugin and version, for each plugin whose scripts on this page pass the budget.
     *
     * @param  array<string, int>  $sizes
     * @param  array<string, string>  $versions
     */
    private function budget(array $sizes, array $versions): void
    {
        foreach ($sizes as $name => $size) {
            if ($size <= self::BUDGET) {
                continue;
            }

            $key = 'plugin-scripts-budget:'.$name.':'.($versions[$name] ?? '');

            if (Cache::add($key, true)) {
                $this->faults->record($name, 'scripts#budget', self::class, new InvalidArgumentException(sprintf('Its scripts on one page are %.1f KB compressed, over the %d KB budget.', $size / 1024, self::BUDGET / 1024)));
            }
        }
    }
}

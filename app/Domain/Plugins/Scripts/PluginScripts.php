<?php

/**
 * The scripts a public page links for the plugin outputs it drew: each recorded output whose enabled
 * plugin agreed to site.scripts and declares a script for it under contributes.scripts, and whose
 * active theme doesn't switch it off. Core serves each script at its own route with the script's content
 * hash as its version. A script's csp origins the plugin agreed to join the page's policy. A plugin whose
 * scripts pass 20 KB compressed on one page is recorded as a fault once per version, never blocked.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Scripts;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Styles\PluginStyleUsage;
use App\Domain\Site\Policy\PagePolicy;
use App\Domain\Themes\ActiveTheme;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class PluginScripts
{
    /** The compressed size, in bytes, a plugin's scripts on one page may reach before a fault is recorded. */
    public const int BUDGET = 20 * 1024;

    public function __construct(
        private readonly PluginStyleUsage $usage,
        private readonly ActiveTheme $theme,
        private readonly PluginScriptFile $files,
        private readonly ListenerFaults $faults,
        private readonly PagePolicy $policy,
    ) {}

    /**
     * Each script to link, as its key (`code:output`) and its address with its version, in the order the
     * outputs were drawn; none when nothing drawn has a script that can be served.
     *
     * @return list<array{key: string, href: string}>
     */
    public function links(): array
    {
        $served = $this->files->servedAll();
        $switchedOff = $this->theme->get()?->manifest?->pluginScriptsOff() ?? [];
        $links = [];
        $sizes = [];
        $versions = [];

        foreach ($this->usage->entries() as $entry) {
            $found = $served[$entry['code']] ?? null;

            if ($found === null || in_array($entry['code'].':'.$entry['output'], $switchedOff, true)) {
                continue;
            }

            foreach (DeclaredScripts::entries($found['manifest']->contributes()) as $script) {
                if ($script['output'] !== $entry['output']) {
                    continue;
                }

                $verdict = $this->files->verdict($entry['code'], $script['file'], $found);

                if ($verdict === null) {
                    continue;
                }

                if ($verdict['refused'] !== null) {
                    $this->refuse($found['name'], $entry['code'], $script['file'], $verdict['refused']);

                    continue;
                }

                $sizes[$found['name']] = ($sizes[$found['name']] ?? 0) + $verdict['gzip'];
                $versions[$found['name']] = $found['version'];
                $this->allowOrigins($found['manifest'], $found['consent']);
                $links[] = [
                    'key' => $entry['code'].':'.$entry['output'],
                    'href' => route('teme.plugins.scripts', ['code' => $entry['code'], 'file' => $script['file']]).'?v='.$verdict['hash'],
                ];
            }
        }

        $this->budget($sizes, $versions);

        return $links;
    }

    /**
     * Lets the origins the plugin agreed to in its csp into the page's policy, for the directives the
     * manifest declares. The page's policy is sent after the body is drawn, so a script linked late
     * still counts.
     *
     * @param  array<string, mixed>  $consent
     */
    private function allowOrigins(Manifest $manifest, array $consent): void
    {
        foreach (Consent::insertionHosts($consent, $manifest) as $kind => $hosts) {
            if (isset(PagePolicy::KINDS[$kind]) && $hosts !== []) {
                $this->policy->allowOrigins(PagePolicy::KINDS[$kind], array_map(fn (string $host): string => 'https://'.$host, $hosts));
            }
        }
    }

    /**
     * Records a refused script as the plugin's fault, once for each code and file, so pages that keep asking
     * for it add no more reports.
     */
    private function refuse(string $name, string $code, string $file, string $refused): void
    {
        if (Cache::add('plugin-scripts-fault:'.$code.':'.$file, true)) {
            $this->faults->record($name, $code.'#scripts', self::class, new InvalidArgumentException($refused));
        }
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

<?php

/**
 * The export kinds the enabled plugins declare, oldest enabled first, each
 * wrapped as a PluginKind. A plugin that can't answer exportKinds() still
 * gets its kinds, which then fail when read, so the export reports them as
 * left out. Holds nothing between calls.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Exports;

use App\Domain\Plugins\Seo\SeoPlugins;
use Teme\Contracts\Export\HasExportKinds;
use Throwable;

final readonly class PluginKinds
{
    public function __construct(private SeoPlugins $plugins) {}

    /**
     * @return list<PluginKind>
     */
    public function all(): array
    {
        $kinds = [];

        foreach ($this->plugins->all() as $plugin) {
            $running = $plugin->running;

            if (! $running instanceof HasExportKinds) {
                continue;
            }

            $asked = null;
            $given = function () use (&$asked, $running): array {
                if ($asked === null) {
                    try {
                        $asked = $running->exportKinds();
                    } catch (Throwable $failure) {
                        $asked = $failure;
                    }
                }

                return $asked instanceof Throwable ? throw $asked : $asked;
            };

            foreach (DeclaredExports::of($plugin->manifest) as $declared) {
                $kinds[] = new PluginKind($plugin, $this->plugins, $declared['id'], $declared['noun'], $given);
            }
        }

        return $kinds;
    }
}

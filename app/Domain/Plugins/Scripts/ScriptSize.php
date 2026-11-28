<?php

/**
 * How big a plugin's declared scripts are, compressed, as the Plugins screen shows them: the sum of
 * each declared file's gzip size, and whether it passes the budget. A size is a warning, never a
 * block, so a plugin over the budget still runs.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Scripts;

use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Styles\PluginStylesheet;
use InvalidArgumentException;

final class ScriptSize
{
    /**
     * The compressed size of a plugin's declared scripts and whether it is over the budget, or null
     * when it declares none. Each file is measured where Teme would serve it, through the same checks
     * (no link on its path, inside the package), so the figure is the size of what is served. A file
     * that is refused or missing adds nothing.
     *
     * @return array{bytes: int, over: bool}|null
     */
    public static function of(?FoundPlugin $plugin): ?array
    {
        $manifest = $plugin?->manifest;
        $entries = $manifest === null ? [] : DeclaredScripts::entries($manifest->contributes());

        if ($plugin === null || $entries === []) {
            return null;
        }

        $bytes = 0;

        foreach ($entries as $entry) {
            try {
                $source = app(PluginStylesheet::class)->source($plugin->name, $entry['file']);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($source !== null) {
                $bytes += strlen((string) gzencode((string) file_get_contents($source), 9));
            }
        }

        return ['bytes' => $bytes, 'over' => $bytes > PluginScripts::BUDGET];
    }
}

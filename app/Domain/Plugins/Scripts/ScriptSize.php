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

final class ScriptSize
{
    /**
     * The compressed size of a plugin's declared scripts and whether it is over the budget, or null
     * when it declares none. A declared file that can't be read adds nothing, and a link on its path
     * or a file outside the package adds nothing either.
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

        $root = realpath($plugin->path);
        $bytes = 0;

        foreach ($entries as $entry) {
            $real = $root === false ? false : realpath($plugin->path.'/'.$entry['file']);

            if ($real === false || ! is_file($real) || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $bytes += strlen((string) gzencode((string) file_get_contents($real), 9));
        }

        return ['bytes' => $bytes, 'over' => $bytes > PluginScripts::BUDGET];
    }
}

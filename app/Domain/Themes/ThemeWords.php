<?php

/**
 * The active theme's own words (I-3360): its `lang/{locale}.json` files,
 * read after core's, so a theme may word core's keys its own way. Added
 * only when a public page is drawn, never in the admin, and in place of
 * another theme's when the active theme changes in the same process.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use Closure;
use Illuminate\Translation\FileLoader;

final class ThemeWords
{
    /**
     * Adds the active theme's word folder, drops any other theme's, and
     * forgets what the translator read, so the active theme's words, and
     * no other theme's, count from now on.
     */
    public static function use(): void
    {
        $folder = app(ActiveTheme::class)->get()?->path;
        $words = $folder === null || ! is_dir($folder.'/lang') ? null : $folder.'/lang';
        $translator = app('translator');
        $loader = $translator->getLoader();

        if (! $loader instanceof FileLoader) {
            return;
        }

        $paths = $loader->paths();
        $others = array_filter($paths, fn (string $path): bool => $path !== $words && is_file(dirname($path).'/teme-theme.json'));

        if ($others === [] && ($words === null || in_array($words, $paths, true))) {
            return;
        }

        if ($others !== []) {
            $kept = array_values(array_diff($paths, $others));
            // FileLoader can add a path but not drop one.
            Closure::bind(function (array $kept): void {
                $this->paths = $kept;
            }, $loader, FileLoader::class)($kept);
        }

        // A path, not a JSON path: JSON paths are read before core's lang/,
        // paths after it, and the later file's word wins.
        if ($words !== null && ! in_array($words, $loader->paths(), true)) {
            $translator->addPath($words);
        }

        $translator->setLoaded([]);
    }
}

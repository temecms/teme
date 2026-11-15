<?php

declare(strict_types=1);

namespace App\Domain\Themes;

use Closure;
use Illuminate\Translation\FileLoader;

/**
 * The active theme's own words (I-3360): its `lang/{locale}.json` files,
 * read after core's, so a theme may word core's keys its own way. Added
 * only when a public page is drawn, never in the admin, and swapped when
 * another theme is active in the same process.
 */
final class ThemeWords
{
    /** The theme word folder this process added to the translator, if any. */
    private static ?string $added = null;

    /**
     * Adds the active theme's word folder, in place of a theme's folder
     * added before it, and forgets what the translator read, so the active
     * theme's words, and no other theme's, count from now on.
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

        if ($words === self::$added && ($words === null || in_array($words, $paths, true))) {
            return;
        }

        if (self::$added !== null) {
            $kept = array_values(array_filter($paths, fn (string $path): bool => $path !== self::$added));
            // FileLoader can add a path but not drop one.
            Closure::bind(function (array $kept): void {
                $this->paths = $kept;
            }, $loader, FileLoader::class)($kept);
        }

        self::$added = $words;

        // A path, not a JSON path: JSON paths are read before core's lang/,
        // paths after it, and the later file's word wins.
        if ($words !== null && ! in_array($words, $loader->paths(), true)) {
            $translator->addPath($words);
        }

        $translator->setLoaded([]);
    }
}

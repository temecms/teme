<?php

declare(strict_types=1);

namespace App\Domain\Themes;

use Illuminate\Translation\FileLoader;

/**
 * The active theme's own words (I-3360): its `lang/{locale}.json` files,
 * read after core's, so a theme may word core's keys its own way. Added
 * only when a public page is drawn, never in the admin.
 */
final class ThemeWords
{
    /**
     * Adds the active theme's word folder, once, and forgets what the
     * translator read before it, so the theme's words count from now on.
     */
    public static function use(): void
    {
        $folder = app(ActiveTheme::class)->get()?->path;
        $translator = app('translator');

        if ($folder === null || ! is_dir($folder.'/lang')) {
            return;
        }

        $loader = $translator->getLoader();

        if (! $loader instanceof FileLoader || in_array($folder.'/lang', $loader->paths(), true)) {
            return;
        }

        // A path, not a JSON path: JSON paths are read before core's lang/,
        // paths after it, and the later file's word wins.
        $translator->addPath($folder.'/lang');
        $translator->setLoaded([]);
    }
}

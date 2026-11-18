<?php

/**
 * `teme:themes:cache`: finds and validates Composer's themes once, and
 * writes them to `config('teme.theme_cache')`, so requests stop validating
 * their manifests. `optimize` runs it. The theme_paths folders are never
 * cached.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Themes\ThemeRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class CacheThemes extends Command
{
    /** @var string */
    protected $signature = 'teme:themes:cache';

    /** @var string */
    protected $description = 'Cache the installed themes and their manifests';

    public function handle(ThemeRegistry $themes, Filesystem $files): int
    {
        $path = (string) config('teme.theme_cache');
        $export = $themes->exportComposerThemes();

        $files->ensureDirectoryExists(dirname($path));
        $files->replace($path, '<?php return '.var_export($export, true).';'.PHP_EOL);
        $themes->flush();

        $this->components->info(sprintf('Cached %d %s.', count($export), count($export) === 1 ? 'theme' : 'themes'));

        return self::SUCCESS;
    }
}

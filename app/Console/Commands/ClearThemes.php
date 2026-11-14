<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Themes\ThemeRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * `teme:themes:clear`: deletes the theme cache, so themes are found and
 * validated from Composer's runtime data again. `optimize:clear` runs it.
 */
final class ClearThemes extends Command
{
    /** @var string */
    protected $signature = 'teme:themes:clear';

    /** @var string */
    protected $description = 'Remove the theme cache';

    public function handle(ThemeRegistry $themes, Filesystem $files): int
    {
        $files->delete((string) config('teme.theme_cache'));
        $themes->flush();

        $this->components->info('Theme cache cleared.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Themes\ThemeRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * `teme:themes:link`: links `public/themes/{code}` to each usable theme's
 * `public/` folder, so its built assets are served, replacing a link left
 * from before. Like `storage:link`, it runs at deploy and image build (study
 * X §3.1); on Windows each link is a junction.
 */
final class LinkThemes extends Command
{
    /** @var string */
    protected $signature = 'teme:themes:link';

    /** @var string */
    protected $description = 'Link each usable theme\'s public folder into public/themes';

    public function handle(ThemeRegistry $themes, Filesystem $files): int
    {
        $files->ensureDirectoryExists(public_path('themes'));

        foreach ($themes->all() as $theme) {
            $target = $theme->path.'/public';

            if (! $theme->usable() || ! is_dir($target)) {
                continue;
            }

            $link = public_path('themes/'.$theme->code);

            if (is_link($link)) {
                // Only the link goes: a recursive delete would follow it into the theme.
                windows_os() ? rmdir($link) : unlink($link);
            } elseif (file_exists($link)) {
                $this->components->error("[{$link}] is a real folder or file, not a link, so it was left alone.");

                continue;
            }

            $files->link($target, $link);
            $this->components->info("The [{$link}] link has been connected to [{$target}].");
        }

        return self::SUCCESS;
    }
}

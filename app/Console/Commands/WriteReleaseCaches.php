<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Extensions\Installer\ReleaseCaches;
use Composer\Autoload\ClassLoader;
use Illuminate\Console\Command;

/**
 * `teme:extensions:caches`: the installer's own step, run in a child
 * process: writes a staged release's plugin and theme caches for this
 * core. It registers the release's packages with Composer's runtime data
 * through a loader that can load no class, so no package code runs.
 */
final class WriteReleaseCaches extends Command
{
    /** @var string */
    protected $signature = 'teme:extensions:caches {release : The release folder} {core : The core hash}';

    /** @var string */
    protected $description = 'Write a staged release’s plugin and theme caches; the installer runs it';

    /** @var bool */
    protected $hidden = true;

    public function handle(ReleaseCaches $caches): int
    {
        $release = (string) $this->argument('release');
        $core = (string) $this->argument('core');

        if (getenv('TEME_EXTENSIONS_ROLE') !== 'installer' || ! is_file($release.'/vendor/composer/installed.php')) {
            $this->components->error(__('Only the installer writes a release’s caches, for a release Composer built.'));

            return self::FAILURE;
        }

        // Composer's runtime data only: the loader has no class map, so it loads nothing.
        (new ClassLoader($release.'/vendor'))->register(false);

        return $caches->writeHere($release, $core) ? self::SUCCESS : self::FAILURE;
    }
}

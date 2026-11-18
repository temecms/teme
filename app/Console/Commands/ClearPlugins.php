<?php

/**
 * `teme:plugins:clear`: deletes the plugin cache, so plugins are found and
 * scanned from Composer's runtime data again (I-3353). `optimize:clear` runs it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\PluginRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class ClearPlugins extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins:clear';

    /** @var string */
    protected $description = 'Remove the plugin cache';

    public function handle(PluginRegistry $plugins, Filesystem $files): int
    {
        $files->delete((string) config('teme.plugin_cache'));
        $plugins->flush();

        $this->components->info(__('Plugin cache cleared.'));

        return self::SUCCESS;
    }
}

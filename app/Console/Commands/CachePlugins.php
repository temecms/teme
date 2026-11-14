<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\PluginRegistry;
use Illuminate\Console\Command;

/**
 * `teme:plugins:cache`: reads every plugin, Composer's and the plugin_paths
 * folders, now, and writes its manifest, its class's reading and what its
 * code scan found to `config('teme.plugin_cache')` (I-3353), so requests
 * stop scanning. `optimize` runs it; nothing else writes it. A plugin that
 * changed since is read again by each process until it runs again; the
 * Teme version and the hooks are checked on every read.
 */
final class CachePlugins extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins:cache';

    /** @var string */
    protected $description = 'Cache what Teme found in the installed plugins';

    public function handle(PluginRegistry $plugins): int
    {
        $count = $plugins->writeCache();

        if ($count === null) {
            $this->components->error(__('Teme couldn’t write the plugin cache to :path.', ['path' => (string) config('teme.plugin_cache')]));

            return self::FAILURE;
        }

        $this->components->info(__('Plugins cached: :count.', ['count' => $count]));

        return self::SUCCESS;
    }
}

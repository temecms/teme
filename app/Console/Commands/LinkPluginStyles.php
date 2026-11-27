<?php

/**
 * `teme:plugins:link`: copies each enabled plugin's declared stylesheets into
 * `public/plugins/{code}`, and removes the copies of any plugin that is not
 * enabled. Only the files a manifest declares under contributes.styles are
 * copied or removed, never the rest of the package. It runs at deploy, as
 * `teme:themes:link` does.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Styles\DeclaredStyles;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class LinkPluginStyles extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins:link';

    /** @var string */
    protected $description = 'Copy each enabled plugin\'s declared stylesheets into public/plugins';

    public function handle(PluginRecords $records, PluginRegistry $registry, Filesystem $files): int
    {
        $enabled = [];

        foreach ($records->enabled() as $record) {
            $enabled[(string) $record->name] = true;
        }

        foreach ($registry->all() as $name => $found) {
            if ($found->manifest === null) {
                continue;
            }

            $code = $found->manifest->code();
            $target = public_path('plugins/'.$code);
            $styles = DeclaredStyles::entries($found->manifest->contributes());

            // Only the declared files are touched: an entry is removed or copied, never a folder listed.
            foreach ($styles as $style) {
                $copy = $target.'/'.$style['file'];

                if (is_file($copy)) {
                    $files->delete($copy);
                }
            }

            if (! isset($enabled[(string) $name])) {
                $this->removeEmptyFolders($target, $styles);

                continue;
            }

            foreach ($styles as $style) {
                $source = $found->path.'/'.$style['file'];

                if (! is_file($source)) {
                    continue;
                }

                $files->ensureDirectoryExists(dirname($target.'/'.$style['file']));
                $files->copy($source, $target.'/'.$style['file']);
            }

            if ($styles !== []) {
                $this->components->info("The stylesheets of [{$code}] were copied.");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Removes the folders a declared file sat in, deepest first, each only
     * when it is empty, so a folder with anything else left in it stays.
     *
     * @param  list<array{output: string, file: string}>  $styles
     */
    private function removeEmptyFolders(string $target, array $styles): void
    {
        $folders = [];

        foreach ($styles as $style) {
            $folder = dirname($target.'/'.$style['file']);

            while (strlen($folder) >= strlen($target)) {
                $folders[$folder] = true;
                $folder = dirname($folder);
            }
        }

        $paths = array_keys($folders);
        usort($paths, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($paths as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            }
        }
    }
}

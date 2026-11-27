<?php

/**
 * `teme:plugins:link`: copies each enabled plugin's declared stylesheets into
 * `public/plugins/{code}`, wrapped in the `teme.plugins` cascade layer, and
 * writes `public/plugins/manifest.json` with a content hash for each copy.
 * A copy the manifest no longer declares, or a plugin that is not enabled,
 * is removed, using only what the previous manifest recorded, never a folder
 * listing. A source that is a symbolic link, or sits behind one, is refused.
 * It runs at deploy, as `teme:themes:link` does.
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

    /** The file, under public/plugins, that records each copy's hash. */
    public const string MANIFEST = 'manifest.json';

    public function handle(PluginRecords $records, PluginRegistry $registry, Filesystem $files): int
    {
        $root = public_path('plugins');
        $files->ensureDirectoryExists($root);

        $previous = $this->readManifest($root);
        $enabled = [];

        foreach ($records->enabled() as $record) {
            $enabled[(string) $record->name] = true;
        }

        $next = [];

        foreach ($registry->all() as $name => $found) {
            if ($found->manifest === null) {
                continue;
            }

            $code = $found->manifest->code();
            $target = $root.'/'.$code;
            $declared = isset($enabled[(string) $name]) ? $this->copies($found->path, DeclaredStyles::entries($found->manifest->contributes())) : [];

            // Remove what the previous manifest copied and this one no longer keeps.
            foreach (array_diff_key($previous[$code] ?? [], $declared) as $file => $hash) {
                $this->removeCopy($files, $target, $file);
            }

            foreach ($declared as $file => $copy) {
                $files->ensureDirectoryExists(dirname($target.'/'.$file));
                $files->put($target.'/'.$file, $copy['content']);
            }

            if ($declared !== []) {
                $next[$code] = array_map(fn (array $copy): string => $copy['hash'], $declared);
            }
        }

        // A plugin the registry no longer has keeps nothing: only what the previous manifest recorded goes.
        foreach (array_diff_key($previous, $next) as $code => $copies) {
            foreach ($copies as $file => $hash) {
                $this->removeCopy($files, $root.'/'.$code, $file);
            }
        }

        $files->put($root.'/'.self::MANIFEST, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * The sheets to copy from a package: each declared file that is a real file inside the
     * package, not behind a link, and that imports nothing, wrapped in the teme.plugins layer.
     *
     * @param  list<array{output: string, file: string}>  $styles
     *
     * @return array<string, array{content: string, hash: string}>
     */
    private function copies(string $package, array $styles): array
    {
        $copies = [];
        $root = realpath($package);

        if ($root === false) {
            return [];
        }

        foreach ($styles as $style) {
            $source = $this->insidePackage($root, $package.'/'.$style['file']);

            if ($source === null) {
                $this->components->error("A stylesheet of [{$style['file']}] was refused: it is not a plain file inside the package.");

                continue;
            }

            $css = (string) file_get_contents($source);

            if (preg_match('/@(import|namespace)\b/i', $css) === 1) {
                $this->components->error("A stylesheet of [{$style['file']}] was refused: @import and @namespace aren't allowed.");

                continue;
            }

            $content = $this->layered($css);
            $copies[$style['file']] = ['content' => $content, 'hash' => substr(hash('sha256', $content), 0, 12)];
        }

        return $copies;
    }

    /**
     * The file's real path when it is a plain file inside the package, with no link
     * on it or on any folder between the package and it, or null.
     */
    private function insidePackage(string $root, string $path): ?string
    {
        $real = realpath($path);

        if ($real === false || ! is_file($real) || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $relative = substr($real, strlen($root) + 1);
        $walk = $root;

        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $part) {
            $walk .= DIRECTORY_SEPARATOR.$part;

            if (is_link($walk)) {
                return null;
            }
        }

        return $real;
    }

    /**
     * The stylesheet inside the teme.plugins layer, with a leading @charset moved to the top,
     * since it must come first.
     */
    private function layered(string $css): string
    {
        $charset = '';

        if (preg_match('/@charset\s+"[^"]*";/i', $css, $found) === 1) {
            $charset = $found[0]."\n";
        }

        $body = preg_replace('/@charset\s+"[^"]*";/i', '', $css) ?? $css;

        return $charset.'@layer teme.plugins {'."\n".trim($body)."\n".'}'."\n";
    }

    /**
     * One copy's file removed, then its folders when they are empty.
     */
    private function removeCopy(Filesystem $files, string $target, string $file): void
    {
        $copy = $target.'/'.$file;

        if (is_file($copy)) {
            $files->delete($copy);
        }

        $folder = dirname($copy);

        while (strlen($folder) > strlen($target) && is_dir($folder) && @rmdir($folder)) {
            $folder = dirname($folder);
        }

        if (strlen($folder) === strlen($target) && is_dir($target)) {
            @rmdir($target);
        }
    }

    /**
     * What the last run copied, by code, file and hash; none when there is no manifest.
     *
     * @return array<string, array<string, string>>
     */
    private function readManifest(string $root): array
    {
        $path = $root.'/'.self::MANIFEST;

        if (! is_file($path)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        return is_array($manifest) ? $manifest : [];
    }
}

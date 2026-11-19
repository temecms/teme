<?php

/**
 * A release's plugin and theme caches for one core, and its links to the
 * themes' public files: written by the installer before the release runs,
 * so no request scans its packages (docs/plans/plugin-installer.md).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Installer;

use App\Domain\Extensions\ActiveRelease;
use App\Domain\Plugins\InstalledPackages;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Themes\ThemeRegistry;
use Symfony\Component\Process\Process;

final readonly class ReleaseCaches
{
    /**
     * Writes the caches in a child process, so the release's runtime data
     * never stays in the installer's own. Returns why it failed, or null.
     */
    public function write(string $release, string $core): ?string
    {
        $process = new Process(
            [PHP_BINARY, base_path('artisan'), 'teme:extensions:caches', $release, $core, '--no-interaction'],
            base_path(),
            ['TEME_EXTENSIONS_ROLE' => 'installer'],
            null,
            300,
        );
        $process->run();

        return $process->isSuccessful() ? null : trim($process->getErrorOutput().$process->getOutput());
    }

    /**
     * In the child process: reads every plugin and theme, the image's and
     * the release's, and writes both caches into the release, then links
     * each of its themes' public folders. Whether it was written.
     */
    public function writeHere(string $release, string $core): bool
    {
        $folder = "{$release}/cache/{$core}";

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true)) {
            return false;
        }

        app()->instance(ActiveRelease::class, new ActiveRelease($release, $core));
        app()->forgetInstance(InstalledPackages::class);

        if (app(PluginRegistry::class)->writeCache("{$folder}/teme-plugins.php") === null) {
            return false;
        }

        $themes = app(ThemeRegistry::class)->exportComposerThemes();
        $contents = '<?php return '.var_export($themes, true).';'.PHP_EOL;

        if (@file_put_contents("{$folder}/teme-themes.php", $contents) !== strlen($contents)) {
            return false;
        }

        return $this->linkThemes($release, $themes);
    }

    /**
     * Links public/themes/{code} to each of the release's own themes' public
     * folders, relative, so nginx serves them from the current release.
     *
     * @param  array<string, array{code: string, path: string, manifest: array<string, mixed>|null, errors: list<string>}>  $themes
     */
    private function linkThemes(string $release, array $themes): bool
    {
        $links = "{$release}/public/themes";

        if (! is_dir($links) && ! @mkdir($links, 0755, true)) {
            return false;
        }

        $vendor = realpath($release.'/vendor') ?: $release.'/vendor';

        foreach ($themes as $theme) {
            $path = realpath($theme['path']) ?: $theme['path'];

            if ($theme['errors'] !== [] || ! str_starts_with($path, $vendor.'/') || ! is_dir($path.'/public')) {
                continue;
            }

            $link = "{$links}/{$theme['code']}";

            if (! is_link($link) && ! @symlink('../../vendor/'.substr($path, strlen($vendor) + 1).'/public', $link)) {
                return false;
            }
        }

        return true;
    }
}

<?php

/**
 * Every theme on disk: Composer packages of type `teme-theme`, found through
 * Composer's runtime data (InstalledVersions) and cached with their
 * validated manifests by `teme:themes:cache`, then the folders in
 * `config('teme.theme_paths')`, always read live. Each manifest is
 * validated as it is found. It only reads; nothing under a theme is written.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use App\Domain\Extensions\ActiveRelease;
use Composer\InstalledVersions;
use JsonException;

final class ThemeRegistry
{
    /** @var array<string, array<string, Theme>> each discovery, by the settings it was made from */
    private array $memo = [];

    public function __construct(private readonly ManifestValidator $validator) {}

    /**
     * Every theme, keyed by code. A theme whose code an earlier one already
     * took is listed under its folder's path instead, broken.
     *
     * @return array<string, Theme>
     */
    public function all(): array
    {
        $paths = $this->configuredPaths();
        $cache = app(ActiveRelease::class)->themeCache() ?? (string) config('teme.theme_cache');
        $key = json_encode([$paths, $cache], JSON_THROW_ON_ERROR);

        if (! isset($this->memo[$key])) {
            $themes = [];

            foreach ([...$this->composerThemes($cache), ...array_map(fn (string $path): Theme => $this->load($path, basename($path)), $paths)] as $theme) {
                $this->add($themes, $theme);
            }

            $this->memo[$key] = $themes;
        }

        return $this->memo[$key];
    }

    /**
     * The theme with this code, usable or not, or null when none has it.
     */
    public function find(string $code): ?Theme
    {
        return $this->all()[$code] ?? null;
    }

    /**
     * Forgets every discovery, so the next read looks again.
     */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * Composer's themes, found and validated now, never from the cache, in
     * the form `teme:themes:cache` writes: code, path, raw manifest and errors.
     *
     * @return array<string, array{code: string, path: string, manifest: array<string, mixed>|null, errors: list<string>}>
     */
    public function exportComposerThemes(): array
    {
        $themes = [];

        foreach ($this->discoverComposer() as $theme) {
            $this->add($themes, $theme);
        }

        return array_map(fn (Theme $theme): array => [
            'code' => $theme->code,
            'path' => $theme->path,
            'manifest' => $theme->manifest?->raw(),
            'errors' => $theme->errors,
        ], $themes);
    }

    /**
     * @param  array<string, Theme>  $themes
     */
    private function add(array &$themes, Theme $theme): void
    {
        if (! isset($themes[$theme->code])) {
            $themes[$theme->code] = $theme;

            return;
        }

        $taken = "The theme code \"{$theme->code}\" is already used by {$themes[$theme->code]->path}.";
        $themes[$theme->path] = new Theme($theme->code, $theme->path, $theme->manifest, [...$theme->errors, $taken]);
    }

    /**
     * Composer's themes, from the cache file when there is one.
     *
     * @return list<Theme>
     */
    private function composerThemes(string $cache): array
    {
        $cached = $cache !== '' && is_file($cache) ? require $cache : null;

        if (! is_array($cached)) {
            return $this->discoverComposer();
        }

        return array_values(array_map(fn (array $theme): Theme => new Theme(
            $theme['code'],
            $theme['path'],
            $theme['manifest'] === null ? null : new Manifest($theme['manifest']),
            $theme['errors'],
        ), $cached));
    }

    /**
     * Every installed package of type `teme-theme`, from Composer's runtime
     * data (`vendor/composer/installed.php`, which OPcache keeps compiled),
     * each at its install path, and each once.
     *
     * @return list<Theme>
     */
    private function discoverComposer(): array
    {
        $themes = [];

        // Composer reports a package once per autoloader that has it, and
        // every package twice after InstalledVersions::reload().
        foreach (array_unique(InstalledVersions::getInstalledPackagesByType('teme-theme')) as $package) {
            $path = InstalledVersions::getInstallPath($package);

            // A metapackage has no install path.
            if ($path !== null) {
                $themes[] = $this->load(realpath($path) ?: $path, null);
            }
        }

        return $themes;
    }

    /**
     * The theme in this folder, validated. $folderCode is the folder's name
     * for a theme_paths folder, whose code must match it; Composer names its
     * packages' folders, so theirs is null.
     */
    private function load(string $path, ?string $folderCode): Theme
    {
        $file = $path.'/teme-theme.json';

        try {
            $raw = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            return new Theme($folderCode ?? basename($path), $path, null, ["teme-theme.json is not valid JSON: {$exception->getMessage()}."]);
        }

        if (! is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return new Theme($folderCode ?? basename($path), $path, null, [is_file($file) ? 'teme-theme.json must hold one JSON object.' : 'The theme has no teme-theme.json.']);
        }

        /** @var array<string, mixed> $raw */
        $manifest = new Manifest($raw);
        $errors = $this->validator->errors($raw, $path);

        if ($folderCode !== null && $manifest->code() !== $folderCode) {
            $errors[] = "The theme code \"{$manifest->code()}\" must match its folder's name, \"{$folderCode}\".";
        }

        // Composers need their classes loaded, so they are checked here, not by the validator.
        foreach ($manifest->composers() as $composer) {
            $refusal = ComposerRegistry::refusal($composer);

            if ($refusal !== null) {
                $errors[] = $refusal->getMessage();
            }
        }

        return new Theme($manifest->code() !== '' ? $manifest->code() : ($folderCode ?? basename($path)), $path, $manifest, $errors);
    }

    /**
     * The theme_paths folders that hold a manifest.
     *
     * @return list<string>
     */
    private function configuredPaths(): array
    {
        $paths = config('teme.theme_paths');
        $found = [];

        foreach (is_array($paths) ? $paths : [] as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            // Relative entries are against the project root, not the process cwd
            // (public/ under a web server): issue #339.
            $resolved = self::absolutePath($path);

            if (is_file($resolved.'/teme-theme.json')) {
                $found[] = realpath($resolved) ?: $resolved;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * An absolute folder path: absolute entries unchanged, relative ones from base_path().
     */
    private static function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || (strlen($path) > 1 && $path[1] === ':')) {
            return $path;
        }

        return base_path($path);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

/**
 * The release of the extensions folder this process loaded, as
 * bootstrap/extensions.php found it before Laravel started: its folder and
 * the core it was checked against, or the release held back because it
 * wasn't checked against this core. Never in config: the release changes
 * while config stays cached.
 */
final readonly class ActiveRelease
{
    /**
     * Each value null reads the constant the bootstrap defined; a test
     * passes its own.
     */
    public function __construct(
        private ?string $release = null,
        private ?string $core = null,
        private ?string $held = null,
    ) {}

    /** Returns the loaded release's folder, or null when none is loaded. */
    public function path(): ?string
    {
        return $this->release ?? (defined('TEME_EXTENSIONS_RELEASE') ? (string) constant('TEME_EXTENSIONS_RELEASE') : null);
    }

    /** Returns the core hash the loaded release was checked against, or null. */
    public function core(): ?string
    {
        return $this->core ?? (defined('TEME_EXTENSIONS_CORE') ? (string) constant('TEME_EXTENSIONS_CORE') : null);
    }

    /** Returns the folder of a release held back for this core, or null when none is. */
    public function held(): ?string
    {
        return $this->held ?? (defined('TEME_EXTENSIONS_HELD') ? (string) constant('TEME_EXTENSIONS_HELD') : null);
    }

    /** Returns the loaded release's plugin cache for this core, or null when it has none. */
    public function pluginCache(): ?string
    {
        return $this->cacheFile('teme-plugins.php');
    }

    /** Returns the loaded release's theme cache for this core, or null when it has none. */
    public function themeCache(): ?string
    {
        return $this->cacheFile('teme-themes.php');
    }

    /** Returns the loaded release's vendor/composer/installed.json, or null when none is loaded. */
    public function installedJson(): ?string
    {
        $path = $this->path();

        return $path === null ? null : $path.'/vendor/composer/installed.json';
    }

    private function cacheFile(string $name): ?string
    {
        $path = $this->path();
        $core = $this->core();

        if ($path === null || $core === null) {
            return null;
        }

        $file = "{$path}/cache/{$core}/{$name}";

        return is_file($file) ? $file : null;
    }
}

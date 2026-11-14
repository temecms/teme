<?php

declare(strict_types=1);

namespace App\Domain\Themes;

use Illuminate\Foundation\Vite;

/**
 * Vite for one theme's own build: it reads the theme's manifest where the
 * theme keeps it, so no `public/` link is needed to find it, and serves its
 * files from `themes/{code}/build`. A new instance per theme, so the
 * admin's shared Vite is never reconfigured.
 */
final class ThemeVite extends Vite
{
    private string $themeManifest = '';

    /**
     * Where the theme's Vite manifest is on disk.
     */
    public function useThemeManifest(string $path): self
    {
        $this->themeManifest = $path;

        return $this;
    }

    protected function manifestPath($buildDirectory)
    {
        return $this->themeManifest !== '' ? $this->themeManifest : parent::manifestPath($buildDirectory);
    }
}

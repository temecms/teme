<?php

declare(strict_types=1);

namespace App\Domain\Themes;

use App\Domain\Site\Policy\PagePolicy;
use Illuminate\Support\Facades\App;

/**
 * The active theme's built assets as tags: its manifest's entries, from its
 * own Vite build at `public/themes/{code}/build`, which `teme:themes:link`
 * serves. The document shell places them in each page's `<head>`, and
 * `@temeThemeAssets` echoes them for a view that writes its own document.
 */
final readonly class ThemeAssets
{
    public function __construct(private ActiveTheme $active) {}

    /**
     * The `<link>` and `<script type="module">` tags for the active theme's
     * entries, or '' with no usable theme, or one with no entries.
     */
    public function tags(): string
    {
        $theme = $this->active->get();
        $manifest = $theme?->manifest;

        if ($theme === null || $manifest === null || $manifest->assetEntries() === [] || $manifest->assetsManifest() === '') {
            return '';
        }

        $vite = (new ThemeVite)
            ->useBuildDirectory("themes/{$theme->code}/build")
            ->useHotFile($theme->path.'/public/hot')
            ->useThemeManifest($theme->path.'/'.$manifest->assetsManifest());

        // Vite throws without a manifest, and a theme never built must not
        // take the site down; `teme:theme:check` reports it instead.
        if (! $vite->isRunningHot() && ! is_file($theme->path.'/'.$manifest->assetsManifest())) {
            return '';
        }

        if ($vite->isRunningHot() && App::isLocal()) {
            $this->allowDevServer(rtrim(trim((string) file_get_contents($vite->hotFile())), '/'));
        }

        return $vite($manifest->assetEntries())->toHtml();
    }

    /**
     * The theme's dev server, while it runs in the local environment, in
     * the page's policy (I-3375), as the admin's allows its own.
     */
    private function allowDevServer(string $origin): void
    {
        $policy = app(PagePolicy::class);

        $policy->allowOrigins('script-src', [$origin]);
        $policy->allowOrigins('style-src', [$origin]);
        $policy->allowOrigins('connect-src', [$origin, (string) preg_replace('#^http#', 'ws', $origin)]);
    }
}

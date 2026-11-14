<?php

declare(strict_types=1);

namespace App\Domain\Themes;

use Illuminate\Support\Facades\View;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The theme the public site is drawn with: the one `design.theme` names,
 * when it is usable, or null, and core's fallback views draw the site. It is
 * read once per request or job, on first use and never at boot, and then
 * points the `theme::` view namespace at the theme's views or the fallback.
 */
final class ActiveTheme
{
    private bool $read = false;

    private ?Theme $theme = null;

    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly SettingsReader $settings,
    ) {}

    /**
     * The usable theme `design.theme` names, or null when it names none, or one that is broken.
     */
    public function get(): ?Theme
    {
        if (! $this->read) {
            $this->read = true;
            $chosen = $this->themes->find($this->chosen());
            $this->theme = $chosen !== null && $chosen->usable() ? $chosen : null;
            $this->point();
        }

        return $this->theme;
    }

    /**
     * The code `design.theme` names, whether or not such a theme is installed or usable.
     */
    public function chosen(): string
    {
        return $this->settings->string('design', 'theme');
    }

    /**
     * Reads the setting again and points `theme::` at the result, with no
     * view found before still cached.
     */
    public function forget(): void
    {
        $this->read = false;
        $this->get();
    }

    private function point(): void
    {
        View::replaceNamespace('theme', $this->theme !== null ? $this->theme->path.'/views' : resource_path('views/teme/fallback'));

        // replaceNamespace() leaves every view already found cached, so a
        // second theme would still draw the first one's templates. A
        // long-lived worker may meet a new theme on its next job, too.
        View::getFinder()->flush();
    }
}

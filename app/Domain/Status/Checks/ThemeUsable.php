<?php

/**
 * Says when the theme `design.theme` names can't draw the site: it isn't
 * installed, or it is broken, and visitors see core's plain fallback. It
 * names the first error, and links to where the theme is chosen.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\ThemeRegistry;

final readonly class ThemeUsable implements StatusCheck
{
    public function __construct(
        private ActiveTheme $active,
        private ThemeRegistry $themes,
    ) {}

    /**
     * The check's stable key, matched by Home's summary rows.
     *
     * @since 1.6.0
     */
    public function key(): string
    {
        return 'theme-usable';
    }

    public function ability(): string
    {
        return 'settings.design.manage';
    }

    /**
     * @since 0.8.0-alpha.1
     */
    public function label(): string
    {
        return __('Theme');
    }

    public function banner(): ?StatusBanner
    {
        $chosen = $this->active->chosen();
        $theme = $this->themes->find($chosen);

        if ($theme !== null && $theme->usable()) {
            return null;
        }

        $message = $theme === null
            ? "The site's theme \"{$chosen}\" isn't installed, so visitors see a plain fallback."
            : "The site's theme \"{$chosen}\" can't be used, so visitors see a plain fallback: ".($theme->errors[0] ?? 'its files couldn’t be read.');

        return new StatusBanner('error', $message, 'Choose a theme', route('themes.index'), 100);
    }
}

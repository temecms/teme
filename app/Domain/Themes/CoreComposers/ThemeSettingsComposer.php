<?php

/**
 * Core's composer for a theme's own settings (study B §3c), run on every
 * theme view after NavigationComposer: `themeSettings`, the active theme's
 * settings key => value, without its menu places, read through SiteDesign,
 * so a design preview shows its drafts. A template reads
 * `$themeSettings['feed_layout']`, never the settings reader.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes\CoreComposers;

use App\Domain\Design\SiteDesign;
use Teme\Contracts\Themes\ViewComposer;
use Teme\Contracts\Themes\ViewContext;

final readonly class ThemeSettingsComposer implements ViewComposer
{
    public function __construct(private SiteDesign $design) {}

    public function views(): array
    {
        return ['*'];
    }

    public function with(ViewContext $context): array
    {
        return ['themeSettings' => $this->design->themeSettings()];
    }
}

<?php

/**
 * What core draws from the design settings: the site's brand colour, the two
 * theme settings core reads by fixed name when the active theme declares
 * them, `color_scheme` and `featured_image_style` (docs/architecture/themes.md),
 * and all the active theme's own settings for its views: the one reader of
 * a theme's settings.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design;

use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\ManifestValidator;
use Teme\Contracts\Settings\SettingsReader;

final readonly class SiteDesign
{
    /** The schemes `color_scheme` may name; auto follows the reader's own. */
    public const array SCHEMES = ['auto', 'light', 'dark'];

    public function __construct(
        private ActiveTheme $active,
        private SettingsReader $settings,
        private SettingsRegistry $registry,
    ) {}

    /**
     * The site's brand colour, `#rrggbb`, or '' when unset (issue 261).
     */
    public function brand(): string
    {
        return $this->settings->string('design', 'brand');
    }

    /**
     * Whether the active theme uses the site's brand as its accent
     * (`use_brand_colour`), so Document writes accent from brand.
     *
     * @since next
     */
    public function usesBrandAsAccent(): bool
    {
        return $this->themeSetting('use_brand_colour') === true;
    }

    /**
     * The active theme's chosen style variation (issue 371), or null when
     * the theme declares none or the value is not one of them. A design
     * preview's draft is read through SettingsReader.
     *
     * @since next
     */
    public function style(): ?string
    {
        $styles = $this->active->get()?->manifest?->styles() ?? [];

        if ($styles === []) {
            return null;
        }

        $chosen = $this->themeSetting(ManifestValidator::STYLE_SETTING);

        return is_string($chosen) && array_key_exists($chosen, $styles) ? $chosen : array_key_first($styles);
    }

    /**
     * The colour scheme the page is drawn in: the active theme's
     * `color_scheme` when it declares one with a known value, else auto.
     */
    public function scheme(): string
    {
        $scheme = $this->themeSetting('color_scheme');

        return in_array($scheme, self::SCHEMES, true) ? $scheme : 'auto';
    }

    /**
     * Whether an article leads with its featured image: false only when the
     * active theme's `featured_image_style` is hidden.
     */
    public function showsFeaturedImage(): bool
    {
        return $this->themeSetting('featured_image_style') !== 'hidden';
    }

    /**
     * The active theme's own settings, key => value, as its `theme.{code}`
     * group holds them (a design preview's drafts over the live values),
     * without its menu places; [] with no usable theme or no such group.
     * Every theme view gets them as `$themeSettings` (ThemeSettingsComposer).
     *
     * @return array<string, mixed>
     */
    public function themeSettings(): array
    {
        $group = $this->group();

        if ($group === null) {
            return [];
        }

        return array_filter(
            $this->settings->group($group),
            fn (string $key): bool => ! str_starts_with($key, ThemeSlotSettings::SETTING_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * One of the active theme's own settings, or null when there is no
     * active theme or it doesn't declare that setting.
     */
    private function themeSetting(string $key): mixed
    {
        $group = $this->group();

        if ($group === null || $this->registry->find($group)?->setting($key) === null) {
            return null;
        }

        return $this->settings->get($group, $key);
    }

    /**
     * The active theme's settings group, or null with no usable theme or
     * no group registered for it.
     */
    private function group(): ?string
    {
        $theme = $this->active->get();
        $group = $theme === null ? null : ThemeSlotSettings::groupKey($theme->code);

        return $group !== null && $this->registry->find($group) !== null ? $group : null;
    }
}

<?php

/**
 * What core draws from the design settings: the site's accent, the two
 * theme settings core reads by fixed name when the active theme declares
 * them, `color_scheme` and `featured_image_style` (docs/architecture/themes.md),
 * and all the active theme's own settings for its views: the one reader of
 * a theme's settings. Scoped per request; themeSettings() is built once.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Media\MediaUrls;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\NavigationTree;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Themes\ActiveTheme;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

final class SiteDesign
{
    /** The schemes `color_scheme` may name; auto follows the reader's own. */
    public const array SCHEMES = ['auto', 'light', 'dark'];

    private string $path = '';

    /** @var array<string, mixed>|null */
    private ?array $themeSettings = null;

    public function __construct(
        private ActiveTheme $active,
        private SettingsReader $settings,
        private SettingsRegistry $registry,
    ) {}

    /**
     * Empties the memo for a page about to be drawn, whose request path
     * marks the current item in theme menu settings.
     */
    public function start(string $path): void
    {
        $this->path = $path;
        $this->themeSettings = null;
    }

    /**
     * The site's accent colour, `#rrggbb`, or '' to use the theme's own.
     */
    public function accent(): string
    {
        return $this->settings->string('design', 'accent');
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
     * Built once per page. An image is `{url, alt}`; a menu is its items as
     * navigation slots give them; every other type keeps its stored value.
     *
     * @return array<string, mixed>
     */
    public function themeSettings(): array
    {
        if ($this->themeSettings !== null) {
            return $this->themeSettings;
        }

        $group = $this->group();

        if ($group === null) {
            return $this->themeSettings = [];
        }

        $values = array_filter(
            $this->settings->group($group),
            fn (string $key): bool => ! str_starts_with($key, ThemeSlotSettings::SETTING_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );

        $types = $this->declaredTypes();

        foreach ($values as $key => $value) {
            $values[$key] = match ($types[$key] ?? null) {
                'image' => $this->imageOf(is_string($value) ? $value : ''),
                'menu' => $this->menuOf(is_string($value) ? $value : ''),
                default => $value,
            };
        }

        return $this->themeSettings = $values;
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

    /**
     * Each of the active theme's declared setting keys to its manifest type.
     *
     * @return array<string, string>
     */
    private function declaredTypes(): array
    {
        $types = [];

        foreach ($this->active->get()?->manifest?->settings() ?? [] as $setting) {
            if (is_string($setting['key'] ?? null) && is_string($setting['type'] ?? null)) {
                $types[$setting['key']] = $setting['type'];
            }
        }

        return $types;
    }

    /**
     * An image setting for a template: its public address and alt text, or
     * null when none is chosen or the image is gone.
     *
     * @return array{url: string, alt: string}|null
     */
    private function imageOf(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $row = DB::table('media')->where('id', $id)->where('kind', 'image')->whereNull('deleted_at')->first(['variants', 'alt']);

        if ($row === null) {
            return null;
        }

        app(RenderRecord::class)->tag(PageTags::media($id));

        $variants = (array) json_decode((string) $row->variants, true);
        $url = MediaUrls::largest($variants, $id) ?? MediaUrls::variant($variants, 'thumb', $id);

        return $url === null ? null : ['url' => $url, 'alt' => (string) ($row->alt ?? '')];
    }

    /**
     * A menu setting for a template: its tree as navigation slots give it,
     * or null when none is chosen or the menu is gone.
     *
     * @return list<array{label: string, url: string|null, current: bool, opensNewTab: bool, heading: bool, children: list<mixed>}>|null
     */
    private function menuOf(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $menu = Navigation::query()->find($id);

        return $menu === null ? null : NavigationTree::for($menu, $this->path);
    }
}

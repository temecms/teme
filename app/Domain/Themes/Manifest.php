<?php

declare(strict_types=1);

namespace App\Domain\Themes;

/**
 * A theme's teme-theme.json, read into typed getters (study B §3a, I-243).
 * It holds what the file says even when ManifestValidator found errors, so a
 * broken theme can still be named; a getter whose value is missing or of the
 * wrong type answers empty. Keys 1.6b doesn't use are kept in raw().
 */
final readonly class Manifest
{
    /** Listing pages' length when a theme doesn't set its own, as 1.6a's listings were. */
    public const int PER_PAGE = 10;

    /** Between a page's title and the site's name when a theme doesn't say. */
    public const string TITLE_SEPARATOR = '–';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    public function code(): string
    {
        return $this->string('code');
    }

    public function name(): string
    {
        return $this->string('name');
    }

    public function version(): string
    {
        return $this->string('version');
    }

    public function description(): string
    {
        return $this->string('description');
    }

    /**
     * The Teme versions the theme works with, such as "^0.1", or '' when it doesn't say.
     */
    public function requiresTeme(): string
    {
        $requires = $this->raw['requires'] ?? null;

        return is_array($requires) && is_string($requires['teme'] ?? null) ? $requires['teme'] : '';
    }

    /**
     * The page templates the theme offers, per content type: type => [slug => label], in the file's order.
     *
     * @return array<string, array<string, string>>
     */
    public function templates(): array
    {
        return $this->labelledMaps('templates');
    }

    /**
     * The navigation slots the theme draws: slug => label (1.6d).
     *
     * @return array<string, string>
     */
    public function navigations(): array
    {
        $navigations = $this->raw['navigations'] ?? null;

        return is_array($navigations) ? array_filter($navigations, fn (mixed $label, mixed $slug): bool => is_string($slug) && is_string($label), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * The regions the theme draws, slug => their declaration (1.6e).
     *
     * @return array<string, array<string, mixed>>
     */
    public function regions(): array
    {
        $regions = $this->raw['regions'] ?? null;

        return is_array($regions) ? array_filter($regions, fn (mixed $region, mixed $slug): bool => is_string($slug) && is_array($region), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * The theme's own settings as its manifest lists them (1.6f), each
     * `{key, type, label, description?, fieldset, options?, default,
     * visible_when?}`, or [] when it declares none.
     *
     * @return list<array<string, mixed>>
     */
    public function settings(): array
    {
        $settings = $this->raw['settings'] ?? null;

        return is_array($settings) && array_is_list($settings) ? array_values(array_filter($settings, is_array(...))) : [];
    }

    /**
     * The theme's screenshot for Settings › Design, relative to the theme's
     * folder, as written, or '' when it declares none (1.6f).
     */
    public function screenshot(): string
    {
        return $this->string('screenshot');
    }

    /**
     * The theme's Vite manifest, relative to the theme, or '' when it has no built assets.
     */
    public function assetsManifest(): string
    {
        $assets = $this->raw['assets'] ?? null;

        return is_array($assets) && is_string($assets['manifest'] ?? null) ? $assets['manifest'] : '';
    }

    /**
     * The Vite entries the theme's pages load, as named in its Vite manifest.
     *
     * @return list<string>
     */
    public function assetEntries(): array
    {
        $assets = $this->raw['assets'] ?? null;
        $entries = is_array($assets) ? ($assets['entries'] ?? null) : null;

        return is_array($entries) ? array_values(array_filter($entries, 'is_string')) : [];
    }

    /**
     * The theme's composer classes, in the file's order.
     *
     * @return list<string>
     */
    public function composers(): array
    {
        $composers = $this->raw['composers'] ?? null;

        return is_array($composers) ? array_values(array_filter($composers, 'is_string')) : [];
    }

    /**
     * How many items a listing page shows.
     */
    public function perPage(): int
    {
        $perPage = $this->raw['per_page'] ?? null;

        return is_int($perPage) ? $perPage : self::PER_PAGE;
    }

    /**
     * What goes between the parts of a page's title, such as "Story – Site".
     */
    public function titleSeparator(): string
    {
        $separator = $this->raw['title_separator'] ?? null;

        return is_string($separator) && $separator !== '' ? $separator : self::TITLE_SEPARATOR;
    }

    /**
     * The layout's widths in pixels, `full` null for the viewport, or null when the theme doesn't say.
     *
     * @return array{content: int, wide: int, full: int|null}|null
     */
    public function layout(): ?array
    {
        $layout = $this->raw['layout'] ?? null;

        if (! is_array($layout) || ! is_int($layout['content'] ?? null) || ! is_int($layout['wide'] ?? null)) {
            return null;
        }

        return ['content' => $layout['content'], 'wide' => $layout['wide'], 'full' => is_int($layout['full'] ?? null) ? $layout['full'] : null];
    }

    /**
     * The image contexts the theme draws, each with its `sizes` value, such as "content".
     *
     * @return array<string, string>
     */
    public function images(): array
    {
        $images = $this->raw['images'] ?? null;

        return is_array($images) ? array_filter($images, fn (mixed $sizes, mixed $context): bool => is_string($context) && is_string($sizes), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * The theme's fonts, their files relative to its public folder.
     *
     * @return list<array{family: string, file: string, weight: string, style: string, preload: bool}>
     */
    public function fonts(): array
    {
        $fonts = $this->raw['fonts'] ?? null;
        $clean = [];

        foreach (is_array($fonts) ? $fonts : [] as $font) {
            if (is_array($font) && is_string($font['family'] ?? null) && is_string($font['file'] ?? null)) {
                $clean[] = [
                    'family' => $font['family'],
                    'file' => $font['file'],
                    'weight' => is_scalar($font['weight'] ?? null) ? (string) $font['weight'] : '400',
                    'style' => is_string($font['style'] ?? null) ? $font['style'] : 'normal',
                    'preload' => ($font['preload'] ?? false) === true,
                ];
            }
        }

        return $clean;
    }

    /**
     * The one font the page preloads, or null.
     *
     * @return array{family: string, file: string, weight: string, style: string, preload: bool}|null
     */
    public function preloadedFont(): ?array
    {
        foreach ($this->fonts() as $font) {
            if ($font['preload']) {
                return $font;
            }
        }

        return null;
    }

    /**
     * The design tokens the theme sets, name => CSS value (I-246).
     *
     * @return array<string, string>
     */
    public function tokens(): array
    {
        $tokens = $this->raw['tokens'] ?? null;

        return is_array($tokens) ? array_filter($tokens, fn (mixed $value, mixed $name): bool => is_string($name) && is_string($value), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * The theme's tokens for its dark scheme (1.6f), name => CSS value, or []
     * when it ships none. 1.6g writes them into the shell.
     *
     * @return array<string, string>
     */
    public function tokensDark(): array
    {
        $tokens = $this->raw['tokens_dark'] ?? null;

        return is_array($tokens) ? array_filter($tokens, fn (mixed $value, mixed $name): bool => is_string($name) && is_string($value), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * Whether pages carry speculation rules; a theme may only opt out.
     */
    public function speculation(): bool
    {
        return ($this->raw['speculation'] ?? true) !== false;
    }

    /**
     * Whether the theme draws a breadcrumb trail, so its views are given the
     * trail and search engines its BreadcrumbList (I-702); false when it
     * doesn't say.
     */
    public function breadcrumbs(): bool
    {
        return ($this->raw['breadcrumbs'] ?? false) === true;
    }

    /**
     * The cards the theme styles itself, whose core stylesheets it replaces (I-248).
     *
     * @return list<string>
     */
    public function replacedCards(): array
    {
        $cards = $this->raw['cards'] ?? null;
        $replaces = is_array($cards) ? ($cards['replaces'] ?? null) : null;

        return is_array($replaces) ? array_values(array_filter($replaces, 'is_string')) : [];
    }

    /**
     * The outside origins the theme's pages load from, by kind (I-3375):
     * each host it listed as https://host, in lower case; kinds it didn't
     * list are absent, and [] when it lists none.
     *
     * @return array<string, list<string>>
     */
    public function csp(): array
    {
        $csp = $this->raw['csp'] ?? null;
        $origins = [];

        if (! is_array($csp)) {
            return [];
        }

        foreach ($csp as $kind => $hosts) {
            if (is_string($kind) && is_array($hosts)) {
                $origins[$kind] = array_values(array_map(fn (string $host): string => 'https://'.strtolower($host), array_filter($hosts, 'is_string')));
            }
        }

        return $origins;
    }

    /**
     * The whole file, as parsed.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    private function string(string $key): string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function labelledMaps(string $key): array
    {
        $maps = $this->raw[$key] ?? null;

        if (! is_array($maps)) {
            return [];
        }

        $clean = [];

        foreach ($maps as $group => $entries) {
            if (is_string($group) && is_array($entries)) {
                $clean[$group] = array_filter($entries, fn (mixed $label, mixed $slug): bool => is_string($slug) && is_string($label), ARRAY_FILTER_USE_BOTH);
            }
        }

        return $clean;
    }
}

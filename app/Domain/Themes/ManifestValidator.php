<?php

/**
 * Checks a teme-theme.json against manifest version 1 and the theme's own
 * files, and says in plain words what is wrong. A theme with any error is
 * broken and never used. The code's folder rule and duplicate codes need the
 * other themes, so ThemeRegistry checks those; later parts (1.6c onward)
 * extend this for the keys they bring into use.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\Plugin\PluginCardNode;
use App\Domain\Cards\Theme\ThemeCardNode;
use App\Domain\Extensions\PackagePictures;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\HostPattern;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Site\Policy\PagePolicy;
use Composer\Semver\Semver;
use Teme\Contracts\Settings\Setting;
use UnexpectedValueException;

final class ManifestValidator
{
    /** A theme's code, as a route constrains its parameter: 2 to 40 lower-case letters, digits and hyphens. */
    public const string CODE_PATTERN = '[a-z0-9][a-z0-9-]{1,39}';

    /** A theme's code, with its delimiters, as the schema's pattern says it. */
    public const string CODE = '/^'.self::CODE_PATTERN.'$/';

    /**
     * The manifest's `screenshot`: a path inside the theme, with no `..`
     * segment or backslash, to a .png, .jpg or .webp image (1.6f).
     */
    public const string SCREENSHOT = '~^(?!/)(?!(?:.*/)?\.\.(?:/|$))[^\\\\\s:]+\.(?:png|jpg|webp)$~i';

    /** A template, navigation or region slug, or a content type's key. */
    public const string SLUG = '/^[a-z0-9-]+$/';

    /** The most cards a theme brings for its regions (docs/guides/developers/themes/regions.md). */
    public const int MOST_PROVIDED_CARDS = 12;

    /** A composer's class name, with its delimiters, as the schema's pattern says it. */
    public const string CLASS_NAME = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /** One length in a `sizes` value: a number in px, vw or rem. */
    private const string LENGTH = '\d+(?:\.\d+)?(?:px|vw|rem)';

    /** A theme setting's key, which the settings registry also takes: 2 to 41 characters. */
    public const string SETTING_KEY = '/^[a-z][a-z0-9_]{1,40}$/';

    /** The setting types a theme may declare. */
    public const array SETTING_TYPES = ['select', 'boolean', 'text', 'color'];

    private const string COLOR = '/^#[0-9a-fA-F]{6}$/';

    /** The tokens contrast is computed from, which must be hex colours. */
    private const array HEX_TOKENS = ['base', 'accent'];

    /** A hex colour, #rgb or #rrggbb, which `base` and `accent` must be. */
    public const string HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    /**
     * The design tokens a theme may set, in the order the schema lists them.
     *
     * @var list<string>
     */
    public const array TOKENS = [
        'base', 'contrast', 'accent', 'on-accent', 'muted', 'rule', 'brand', 'font-body', 'font-heading', 'font-mono',
        'step--1', 'step-0', 'step-1', 'step-2', 'step-3', 'step-4', 'step-5',
        'space-1', 'space-2', 'space-3', 'space-4', 'space-5', 'space-6', 'space-7', 'space-8',
    ];

    /**
     * Core tokens a theme must not set: Document writes them from the site
     * (issue 261).
     *
     * @var list<string>
     */
    public const array READ_ONLY_TOKENS = ['brand'];

    /**
     * A theme's own token name: lower-case letters, digits and hyphens
     * (issue 370).
     */
    public const string CUSTOM_TOKEN = '/^[a-z][a-z0-9-]*$/';

    /**
     * The top-level keys `errors()` reads, in its order (I-246: the tokens are
     * a fixed vocabulary, so a colour scheme or a type pairing is only a swap
     * of values).
     *
     * @var list<string>
     */
    public const array KEYS = [
        'manifest', 'code', 'name', 'version', 'description', 'requires', 'author_url', 'homepage', 'templates', 'navigations', 'regions',
        'settings', 'assets', 'title_separator', 'layout', 'images', 'fonts', 'tokens', 'tokens_dark',
        'custom_tokens', 'custom_tokens_dark', 'styles',
        'speculation', 'breadcrumbs', 'screenshot', 'cards', 'composers', 'per_page', 'csp', 'icon', 'screenshots',
    ];

    /** The setting core adds when a theme declares styles (issue 371). */
    public const string STYLE_SETTING = 'style';

    public function __construct(private readonly CardRegistry $cards) {}

    /**
     * Every error in the manifest, in the order the keys are checked; empty when it is valid.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    public function errors(array $manifest, string $themePath): array
    {
        $errors = [];

        if (($manifest['manifest'] ?? null) !== 1) {
            $errors[] = 'The manifest must say "manifest": 1.';
        }

        $code = $manifest['code'] ?? null;

        if (! is_string($code) || preg_match(self::CODE, $code) !== 1) {
            $errors[] = 'The theme code must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter or digit.';
        }

        foreach (['name', 'version', 'description'] as $key) {
            if (! is_string($manifest[$key] ?? null) || trim($manifest[$key]) === '') {
                $errors[] = "The manifest needs a {$key}.";
            }
        }

        array_push($errors, ...$this->requires($manifest));
        array_push($errors, ...$this->httpsAddress($manifest, 'author_url'));
        array_push($errors, ...$this->httpsAddress($manifest, 'homepage'));

        // Teme has no parent or child themes (I-3346): silently ignoring the key would draw a site with half its templates missing.
        if (array_key_exists('parent', $manifest) || array_key_exists('extends', $manifest)) {
            $errors[] = 'Teme has no parent or child themes: copy the theme you would build on, and change the copy.';
        }

        array_push($errors, ...$this->templates($manifest, $themePath));
        array_push($errors, ...$this->navigations($manifest));
        array_push($errors, ...$this->regions($manifest));
        array_push($errors, ...$this->settings($manifest));
        array_push($errors, ...$this->assets($manifest));
        array_push($errors, ...$this->titleSeparator($manifest));
        array_push($errors, ...$this->layout($manifest));
        array_push($errors, ...$this->images($manifest));
        array_push($errors, ...$this->fonts($manifest, $themePath));
        array_push($errors, ...$this->tokens($manifest, 'tokens'));
        array_push($errors, ...$this->tokens($manifest, 'tokens_dark'));
        array_push($errors, ...$this->customTokens($manifest, 'custom_tokens'));
        array_push($errors, ...$this->customTokens($manifest, 'custom_tokens_dark'));
        array_push($errors, ...$this->styles($manifest, $themePath));
        array_push($errors, ...$this->speculation($manifest));
        array_push($errors, ...$this->breadcrumbs($manifest));
        array_push($errors, ...$this->csp($manifest));

        if (array_key_exists('screenshot', $manifest) && (! is_string($manifest['screenshot']) || preg_match(self::SCREENSHOT, $manifest['screenshot']) !== 1)) {
            $errors[] = 'screenshot must be a path inside the theme, with no .., to a .png, .jpg or .webp image.';
        }

        array_push($errors, ...PackagePictures::errors($manifest, 'theme'));
        array_push($errors, ...$this->cards($manifest, $themePath));

        $composers = $manifest['composers'] ?? [];

        if (! is_array($composers) || ! array_is_list($composers) || array_filter($composers, fn (mixed $class): bool => ! is_string($class) || preg_match(self::CLASS_NAME, $class) !== 1) !== []) {
            $errors[] = 'composers must be a list of class names.';
        }

        $perPage = $manifest['per_page'] ?? Manifest::PER_PAGE;

        if (! is_int($perPage) || $perPage < 1 || $perPage > 100) {
            $errors[] = 'per_page must be a whole number from 1 to 100.';
        }

        if (! is_file($themePath.'/views/templates/index.blade.php')) {
            $errors[] = 'The theme has no views/templates/index.blade.php, which every theme needs.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function requires(array $manifest): array
    {
        $requires = $manifest['requires'] ?? null;
        $constraint = is_array($requires) ? ($requires['teme'] ?? null) : null;
        $version = (string) config('teme.version');

        if (! is_string($constraint) || trim($constraint) === '') {
            return ['The manifest must say which Teme versions the theme works with, as requires.teme.'];
        }

        try {
            $satisfied = Semver::satisfies($version, $constraint);
        } catch (UnexpectedValueException) {
            return ["requires.teme \"{$constraint}\" is not a version constraint."];
        }

        return $satisfied ? [] : ["The theme requires Teme {$constraint}, but this is Teme {$version}."];
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function templates(array $manifest, string $themePath): array
    {
        if (! array_key_exists('templates', $manifest)) {
            return [];
        }

        $templates = $manifest['templates'];

        if (! is_array($templates) || (array_is_list($templates) && $templates !== [])) {
            return ['templates must map each content type to its templates, slug to label.'];
        }

        $errors = [];

        foreach ($templates as $type => $declared) {
            if (! is_string($type) || preg_match(self::SLUG, $type) !== 1 || ! is_array($declared) || (array_is_list($declared) && $declared !== [])) {
                $errors[] = 'templates must map each content type to its templates, slug to label.';

                continue;
            }

            foreach ($declared as $slug => $label) {
                if (! is_string($slug) || preg_match(self::SLUG, $slug) !== 1) {
                    $errors[] = "The template slug \"{$slug}\" for {$type} must be lower-case letters, digits and hyphens.";

                    continue;
                }

                if (! is_string($label) || trim($label) === '') {
                    $errors[] = "The template \"{$slug}\" for {$type} needs a label.";
                }

                // The default is the theme's single-{type} or single; it has no file of its own.
                $name = "single-{$type}-{$slug}";

                if ($slug !== 'default' && ! is_file("{$themePath}/views/templates/{$name}.blade.php")) {
                    $errors[] = "The template \"{$name}\" is declared but views/templates/{$name}.blade.php does not exist.";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function navigations(array $manifest): array
    {
        if (! array_key_exists('navigations', $manifest)) {
            return [];
        }

        $navigations = $manifest['navigations'];

        if (! is_array($navigations) || (array_is_list($navigations) && $navigations !== [])) {
            return ['navigations must map each slot\'s slug to its label.'];
        }

        $errors = [];

        foreach ($navigations as $slug => $label) {
            if (! is_string($slug) || preg_match(self::SLUG, $slug) !== 1 || ! is_string($label) || trim($label) === '') {
                $errors[] = "The navigation \"{$slug}\" needs a slug of lower-case letters, digits and hyphens, and a label.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function regions(array $manifest): array
    {
        if (! array_key_exists('regions', $manifest)) {
            return [];
        }

        $regions = $manifest['regions'];

        if (! is_array($regions) || (array_is_list($regions) && $regions !== [])) {
            return ['regions must map each region\'s slug to its label, cards and max.'];
        }

        $errors = [];
        $known = array_values(array_diff($this->cards->names(), ['plugin-card', 'theme-card']));
        $provided = is_array($manifest['cards'] ?? null) && is_array($manifest['cards']['provides'] ?? null) ? array_map(strval(...), array_keys($manifest['cards']['provides'])) : [];

        foreach ($regions as $slug => $region) {
            if (! is_string($slug) || preg_match(self::SLUG, $slug) !== 1 || ! is_array($region)) {
                $errors[] = "The region \"{$slug}\" needs a slug of lower-case letters, digits and hyphens, and a label.";

                continue;
            }

            if (! is_string($region['label'] ?? null) || trim($region['label']) === '') {
                $errors[] = "The region \"{$slug}\" needs a label.";
            }

            // Omitted or "*", every card; a list narrows it (docs/guides/developers/themes/regions.md).
            $cards = $region['cards'] ?? RegionDeclarations::EVERY;

            if ($cards === RegionDeclarations::EVERY) {
                // Every card.
            } elseif (! is_array($cards) || ! array_is_list($cards)) {
                $errors[] = "The region \"{$slug}\" must list the cards it allows, or say \"*\" for every card.";
            } else {
                foreach ($cards as $card) {
                    // A plugin's card by its key: the plugin may not be installed when the theme is checked.
                    $plugin = is_string($card) && preg_match(PluginCardNode::KEY, $card) === 1;

                    if (! $plugin && (! is_string($card) || ! in_array($card, [...$known, ...$provided], true))) {
                        $errors[] = "The region \"{$slug}\" allows \"{$this->show($card)}\", which isn't a registered card.";
                    }
                }
            }

            if (array_key_exists('group', $region) && (! is_string($region['group']) || trim($region['group']) === '' || mb_strlen($region['group']) > RegionDeclarations::GROUP_LENGTH)) {
                $errors[] = "The region \"{$slug}\" gives its group as a name of 1 to ".RegionDeclarations::GROUP_LENGTH.' characters, such as "Ad placements".';
            }

            if (array_key_exists('size', $region) && (! is_string($region['size']) || preg_match(RegionDeclarations::SIZE, $region['size']) !== 1)) {
                $errors[] = "The region \"{$slug}\" gives its size as width x height in pixels, such as 300x600.";
            }

            if (array_key_exists('minWidth', $region) && (! is_int($region['minWidth']) || $region['minWidth'] < RegionDeclarations::MIN_WIDTH_LEAST || $region['minWidth'] > RegionDeclarations::MIN_WIDTH_MOST)) {
                $errors[] = "The region \"{$slug}\" is drawn from a width of ".RegionDeclarations::MIN_WIDTH_LEAST.' to '.RegionDeclarations::MIN_WIDTH_MOST.' pixels, so minWidth must be a whole number in that range.';
            }

            if (array_key_exists('max', $region) && (! is_int($region['max']) || $region['max'] < 1 || $region['max'] > 50)) {
                $errors[] = "The region \"{$slug}\" may hold 1 to 50 cards, so max must be a whole number in that range.";
            }
        }

        return $errors;
    }

    /**
     * The theme's own settings (1.6f). Everything ThemeSettings::register()
     * would throw on at boot is refused here, so a bad manifest makes the
     * theme unusable instead of stopping every request.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function settings(array $manifest): array
    {
        if (! array_key_exists('settings', $manifest)) {
            return [];
        }

        $settings = $manifest['settings'];

        if (! is_array($settings) || ! array_is_list($settings)) {
            return ['settings must be a list of the theme\'s settings.'];
        }

        $errors = [];
        $declared = [];

        foreach ($settings as $i => $setting) {
            $key = is_array($setting) ? ($setting['key'] ?? null) : null;

            if (! is_array($setting) || ! is_string($key) || preg_match(self::SETTING_KEY, $key) !== 1) {
                $errors[] = "settings.{$i} needs a key of 2 to 41 lower-case letters, digits and underscores, starting with a letter.";

                continue;
            }

            if (str_starts_with($key, ThemeSlotSettings::SETTING_PREFIX)) {
                $errors[] = "The setting \"{$key}\" can't start with nav_, which is kept for navigation slots.";
            }

            if ($key === self::STYLE_SETTING && array_key_exists('styles', $manifest)) {
                $errors[] = "The setting \"{$key}\" is kept for the theme's style variations.";
            }

            if (in_array($key, $declared, true)) {
                $errors[] = "The setting \"{$key}\" is declared twice.";
            }

            array_push($errors, ...$this->setting($key, $setting, $declared));
            $declared[] = $key;
        }

        return $errors;
    }

    /**
     * One declared setting's type, label, description, fieldset, options,
     * default and visibility rule.
     *
     * @param  array<array-key, mixed>  $setting
     * @param  list<string>  $earlier  the keys declared before it
     *
     * @return list<string>
     */
    private function setting(string $key, array $setting, array $earlier): array
    {
        $errors = [];
        $type = $setting['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::SETTING_TYPES, true)) {
            $errors[] = "The setting \"{$key}\" has type \"{$this->show($type)}\": a theme setting is a select, boolean, text or color.";
            $type = null;
        }

        if (! is_string($setting['label'] ?? null) || trim($setting['label']) === '') {
            $errors[] = "The setting \"{$key}\" needs a label.";
        }

        if (array_key_exists('description', $setting) && (! is_string($setting['description']) || trim($setting['description']) === '')) {
            $errors[] = "The setting \"{$key}\" has an empty description: leave it out, or describe the setting.";
        }

        if (! in_array($setting['fieldset'] ?? null, Setting::FIELDSETS, true)) {
            $errors[] = "The setting \"{$key}\" needs a fieldset: ".implode(', ', Setting::FIELDSETS).'.';
        }

        $options = $this->settingOptions($setting['options'] ?? null);

        if ($type === 'select' && $options === null) {
            $errors[] = "The setting \"{$key}\" needs options: an object of stored value to label.";
        }

        if ($type !== null && $type !== 'select' && array_key_exists('options', $setting)) {
            $errors[] = "The setting \"{$key}\" has options, which only a select takes.";
        }

        if (! array_key_exists('default', $setting)) {
            $errors[] = "The setting \"{$key}\" needs a default.";
        } elseif ($type !== null && ($type !== 'select' || $options !== null) && ! $this->validDefault($type, $setting['default'], $options ?? [])) {
            $errors[] = match ($type) {
                'select' => "The setting \"{$key}\" has a default that isn't one of its options.",
                'boolean' => "The setting \"{$key}\" needs a default of true or false.",
                'text' => "The setting \"{$key}\" needs a default that is text.",
                default => "The setting \"{$key}\" needs a default of \"\" or a colour written #rrggbb.",
            };
        }

        if (array_key_exists('visible_when', $setting)) {
            array_push($errors, ...$this->visibleWhen($key, $setting['visible_when'], $earlier));
        }

        return $errors;
    }

    /**
     * A select's options as stored value => label, or null when they aren't
     * a non-empty object of labels.
     *
     * @return array<string, string>|null
     */
    private function settingOptions(mixed $options): ?array
    {
        if (! is_array($options) || $options === [] || array_is_list($options)) {
            return null;
        }

        $labelled = [];

        foreach ($options as $value => $label) {
            if (! is_string($label) || trim($label) === '') {
                return null;
            }

            $labelled[(string) $value] = $label;
        }

        return $labelled;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function validDefault(string $type, mixed $default, array $options): bool
    {
        return match ($type) {
            'select' => is_string($default) && array_key_exists($default, $options),
            'boolean' => is_bool($default),
            'text' => is_string($default),
            default => $default === '' || (is_string($default) && preg_match(self::COLOR, $default) === 1),
        };
    }

    /**
     * @param  list<string>  $earlier
     *
     * @return list<string>
     */
    private function visibleWhen(string $key, mixed $conditions, array $earlier): array
    {
        if (! is_array($conditions) || $conditions === [] || array_is_list($conditions)) {
            return ["The setting \"{$key}\" has a visible_when that must map settings declared before it to a value, or a list of values."];
        }

        $errors = [];

        foreach ($conditions as $other => $value) {
            $values = is_array($value) && array_is_list($value) ? $value : [$value];

            if (! in_array($other, $earlier, true)) {
                $errors[] = "The setting \"{$key}\" depends on \"{$other}\", which must be declared before it.";
            } elseif ($values === [] || array_filter($values, fn (mixed $item): bool => ! is_scalar($item)) !== []) {
                $errors[] = "The setting \"{$key}\" shows when \"{$other}\" has a value, or one of a list of values; this isn't either.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function assets(array $manifest): array
    {
        if (! array_key_exists('assets', $manifest)) {
            return [];
        }

        $assets = $manifest['assets'];

        if (! is_array($assets)) {
            return ['assets must give the theme\'s Vite manifest and entries.'];
        }

        $errors = [];
        $path = $assets['manifest'] ?? null;

        // Relative, and inside the theme: no leading slash, drive or backslash, and no "..".
        if (! is_string($path) || $path === '' || preg_match('#^(/|[A-Za-z]:)|\\\\#', $path) === 1 || in_array('..', explode('/', $path), true)) {
            $errors[] = 'assets.manifest must be a relative path inside the theme.';
        }

        $entries = $assets['entries'] ?? [];

        if (! is_array($entries) || ! array_is_list($entries) || array_filter($entries, fn (mixed $entry): bool => ! is_string($entry) || $entry === '') !== []) {
            $errors[] = 'assets.entries must be a list of the entries the theme\'s Vite build names.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function titleSeparator(array $manifest): array
    {
        if (! array_key_exists('title_separator', $manifest)) {
            return [];
        }

        $separator = $manifest['title_separator'];

        return is_string($separator) && mb_strlen($separator) >= 1 && mb_strlen($separator) <= 3
            ? []
            : ['title_separator must be 1 to 3 characters, such as "–" or "|".'];
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function layout(array $manifest): array
    {
        if (! array_key_exists('layout', $manifest)) {
            return [];
        }

        $layout = $manifest['layout'];

        if (! is_array($layout)) {
            return ['layout must give the content, wide and full widths in pixels.'];
        }

        $errors = [];

        foreach (['content', 'wide'] as $width) {
            if (! is_int($layout[$width] ?? null) || $layout[$width] < 1) {
                $errors[] = "layout.{$width} must be a width in pixels.";
            }
        }

        // Full may be null: the viewport.
        if (($layout['full'] ?? null) !== null && (! is_int($layout['full']) || $layout['full'] < 1)) {
            $errors[] = 'layout.full must be a width in pixels, or null for the whole viewport.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function images(array $manifest): array
    {
        if (! array_key_exists('images', $manifest)) {
            return [];
        }

        $images = $manifest['images'];

        if (! is_array($images) || (array_is_list($images) && $images !== [])) {
            return ['images must map each image context to its sizes value.'];
        }

        $errors = array_key_exists('content', $images) ? [] : ['images must include content, the context cards and the body use.'];

        foreach ($images as $context => $sizes) {
            if (! is_string($sizes) || ! self::validSizes($sizes)) {
                $errors[] = "images.{$context} must be a sizes value: lengths in px, vw or rem, each but the last after a media condition in parentheses, such as \"(min-width: 720px) 680px, 100vw\".";
            }
        }

        return $errors;
    }

    /**
     * Whether this is a valid `sizes` value: entries separated by commas,
     * each but the last a media condition and a length, the last a length.
     */
    private static function validSizes(string $sizes): bool
    {
        $entries = array_map('trim', explode(',', $sizes));
        $last = array_pop($entries);

        foreach ($entries as $entry) {
            if (preg_match('/^(?:not\s+)?\(.+\)\s+'.self::LENGTH.'$/', $entry) !== 1) {
                return false;
            }
        }

        return preg_match('/^'.self::LENGTH.'$/', (string) $last) === 1;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function fonts(array $manifest, string $themePath): array
    {
        if (! array_key_exists('fonts', $manifest)) {
            return [];
        }

        $fonts = $manifest['fonts'];

        if (! is_array($fonts) || ! array_is_list($fonts)) {
            return ['fonts must be a list of fonts, each with its family, file, weight, style and preload.'];
        }

        $errors = [];
        $preloaded = 0;

        foreach ($fonts as $i => $font) {
            if (! is_array($font) || ! is_string($font['family'] ?? null) || trim($font['family']) === '') {
                $errors[] = "fonts.{$i} needs a family.";

                continue;
            }

            $file = $font['file'] ?? null;

            // Relative, inside the theme's public folder, and there.
            if (! is_string($file) || $file === '' || preg_match('#^(/|[A-Za-z]:)|\\\\#', $file) === 1 || in_array('..', explode('/', $file), true)) {
                $errors[] = "The font {$font['family']} needs a file relative to the theme's public folder.";
            } elseif (! is_file("{$themePath}/public/{$file}")) {
                $errors[] = "The font file public/{$file} does not exist.";
            }

            if (! in_array($font['style'] ?? 'normal', ['normal', 'italic', 'oblique'], true)) {
                $errors[] = "The font {$font['family']} has a style that isn't normal, italic or oblique.";
            }

            if (($font['preload'] ?? false) === true) {
                $preloaded++;
            }
        }

        if ($preloaded > 1) {
            $errors[] = 'Only one font may be preloaded (study Q §4): preloading more delays the page.';
        }

        return $errors;
    }

    /**
     * The theme's design tokens, in `tokens`, or in `tokens_dark` for its
     * dark scheme (1.6f). `base` and `accent` must be hex colours, since
     * Teme computes the accent's contrast from them; the rest are free CSS.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function tokens(array $manifest, string $key): array
    {
        if (! array_key_exists($key, $manifest)) {
            return [];
        }

        $tokens = $manifest[$key];

        if (! is_array($tokens) || (array_is_list($tokens) && $tokens !== [])) {
            return ["{$key} must map each token name to its value."];
        }

        $errors = [];
        $where = $key === 'tokens' ? '' : " in {$key}";

        foreach ($tokens as $name => $value) {
            if (! in_array($name, self::TOKENS, true)) {
                $errors[] = "Unknown token \"{$name}\"{$where}: a theme sets only ".implode(', ', self::TOKENS).'.';

                continue;
            }

            if (in_array($name, self::READ_ONLY_TOKENS, true)) {
                $errors[] = "The token \"{$name}\"{$where} is read-only: core writes it from the site's brand colour.";

                continue;
            }

            // The value goes into the page's <style>, so nothing that could end a rule or the tag.
            if (! is_string($value) || trim($value) === '' || preg_match('/[;{}<>]/', $value) === 1) {
                $errors[] = "The token \"{$name}\"{$where} must have a CSS value, with no ; { } < or >.";
            } elseif (in_array($name, self::HEX_TOKENS, true) && preg_match(self::HEX, $value) !== 1) {
                $errors[] = "The token \"{$name}\"{$where} must be a hex colour, #rgb or #rrggbb: Teme checks the accent's contrast against it.";
            }
        }

        return $errors;
    }

    /**
     * A theme's own tokens beyond the core list (issue 370): names of
     * lower-case letters, digits and hyphens that are not a core token,
     * with the same value rule as core tokens.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function customTokens(array $manifest, string $key): array
    {
        if (! array_key_exists($key, $manifest)) {
            return [];
        }

        $tokens = $manifest[$key];

        if (! is_array($tokens) || (array_is_list($tokens) && $tokens !== [])) {
            return ["{$key} must map each token name to its value."];
        }

        $errors = [];
        $where = $key === 'custom_tokens' ? '' : " in {$key}";

        foreach ($tokens as $name => $value) {
            if (! is_string($name) || preg_match(self::CUSTOM_TOKEN, $name) !== 1) {
                $errors[] = "The custom token name \"{$name}\"{$where} must be lower-case letters, digits and hyphens, starting with a letter.";

                continue;
            }

            if (in_array($name, self::TOKENS, true)) {
                $errors[] = "The custom token \"{$name}\"{$where} is a core token name: put it under tokens, or pick another name.";

                continue;
            }

            if (! is_string($value) || trim($value) === '' || preg_match('/[;{}<>]/', $value) === 1) {
                $errors[] = "The custom token \"{$name}\"{$where} must have a CSS value, with no ; { } < or >.";
            }
        }

        return $errors;
    }

    /**
     * Named token sets a theme can switch between (issue 371): each needs a
     * label; tokens, tokens_dark and custom_tokens follow the same rules as
     * the top-level keys; fonts names one declared font file to preload.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function styles(array $manifest, string $themePath): array
    {
        if (! array_key_exists('styles', $manifest)) {
            return [];
        }

        $styles = $manifest['styles'];

        if (! is_array($styles) || $styles === [] || array_is_list($styles)) {
            return ['styles must map each style name to its label and optional tokens.'];
        }

        $errors = [];
        $files = [];

        foreach (is_array($manifest['fonts'] ?? null) ? $manifest['fonts'] : [] as $font) {
            if (is_array($font) && is_string($font['file'] ?? null)) {
                $files[] = $font['file'];
            }
        }

        foreach ($styles as $name => $style) {
            if (! is_string($name) || preg_match(self::SLUG, $name) !== 1) {
                $errors[] = "The style name \"{$name}\" must be lower-case letters, digits and hyphens.";

                continue;
            }

            if (! is_array($style) || ! is_string($style['label'] ?? null) || trim($style['label']) === '') {
                $errors[] = "styles.{$name} needs a label.";

                continue;
            }

            foreach (['tokens', 'tokens_dark'] as $key) {
                if (array_key_exists($key, $style)) {
                    foreach ($this->tokens([$key => $style[$key]], $key) as $error) {
                        $errors[] = "styles.{$name}: {$error}";
                    }
                }
            }

            if (array_key_exists('custom_tokens', $style)) {
                foreach ($this->customTokens(['custom_tokens' => $style['custom_tokens']], 'custom_tokens') as $error) {
                    $errors[] = "styles.{$name}: {$error}";
                }
            }

            if (array_key_exists('fonts', $style)) {
                $file = $style['fonts'];

                if (! is_string($file) || $file === '' || ! in_array($file, $files, true)) {
                    $errors[] = "styles.{$name}.fonts must be the file of one font declared under fonts.";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function speculation(array $manifest): array
    {
        return array_key_exists('speculation', $manifest) && ! is_bool($manifest['speculation'])
            ? ['speculation must be true or false.']
            : [];
    }

    /**
     * The outside hosts the theme's pages load from, by kind (I-3375): an
     * object of known kinds, each a list of at most 20 host names, with no
     * scheme, path, port or keyword, since https is implied.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function csp(array $manifest): array
    {
        if (! array_key_exists('csp', $manifest)) {
            return [];
        }

        $csp = $manifest['csp'];
        $shape = __('csp must be an object whose keys are among script, style, font, img, connect and frame, each a list of host names.');

        if (! is_array($csp) || ($csp !== [] && array_is_list($csp))) {
            return [$shape];
        }

        foreach ($csp as $kind => $hosts) {
            if (! is_string($kind) || ! array_key_exists($kind, PagePolicy::KINDS) || ! is_array($hosts) || ! array_is_list($hosts)) {
                return [$shape];
            }
        }

        $errors = [];

        foreach ($csp as $kind => $hosts) {
            if (count($hosts) > 20) {
                $errors[] = __('csp.:kind lists more than 20 hosts.', ['kind' => $kind]);

                continue;
            }

            foreach ($hosts as $host) {
                if (! is_string($host) || preg_match(HostPattern::PATTERN, $host) !== 1) {
                    $errors[] = __('csp.:kind holds “:value”, which isn\'t a host name. Write it as cdn.example.com or *.example.com, with no https://, path, port or quoted keyword: https is implied.', ['kind' => $kind, 'value' => $this->show($host)]);
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function breadcrumbs(array $manifest): array
    {
        return array_key_exists('breadcrumbs', $manifest) && ! is_bool($manifest['breadcrumbs'])
            ? ['breadcrumbs must be true or false.']
            : [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function cards(array $manifest, string $themePath): array
    {
        if (! array_key_exists('cards', $manifest)) {
            return [];
        }

        $replaces = is_array($manifest['cards']) ? ($manifest['cards']['replaces'] ?? []) : null;

        if (! is_array($replaces) || ! array_is_list($replaces)) {
            return ['cards.replaces must list the cards the theme styles itself.'];
        }

        $errors = [];
        $known = $this->cards->names();

        foreach ($replaces as $card) {
            if (! is_string($card) || ! in_array($card, $known, true)) {
                $errors[] = "cards.replaces names \"{$this->show($card)}\", which isn't a registered card.";
            }
        }

        $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.0.0';

        return [...$errors, ...$this->providedCards($manifest['cards']['provides'] ?? [], $themePath, $version, $known)];
    }

    /**
     * The cards the theme brings (docs/guides/developers/themes/regions.md):
     * by id, each with its words, icon and fields as a plugin's card has
     * them, without choice, and its own view.
     *
     * @param  list<string>  $known  the registered cards' names, which an id can't take
     *
     * @return list<string>
     */
    private function providedCards(mixed $provides, string $themePath, string $version, array $known): array
    {
        if (! is_array($provides) || (array_is_list($provides) && $provides !== [])) {
            return ['cards.provides must map each card\'s id to its label, description and fields.'];
        }

        $errors = count($provides) > self::MOST_PROVIDED_CARDS ? ['A theme can bring at most '.self::MOST_PROVIDED_CARDS.' cards.'] : [];

        foreach ($provides as $id => $card) {
            $id = (string) $id;

            if (preg_match(ThemeCardNode::ID, $id) !== 1) {
                $errors[] = "cards.provides.{$id} needs an id of 2 to 41 lower-case letters, digits and hyphens, starting with a letter.";

                continue;
            }

            if (in_array($id, $known, true)) {
                $errors[] = "cards.provides.{$id} has the name of one of Teme's cards, which a region's list would mean instead.";
            }

            if (! is_array($card)) {
                $errors[] = "cards.provides.{$id} needs a label, a description and its fields.";

                continue;
            }

            foreach (array_keys($card) as $key) {
                if (! in_array($key, ['label', 'description', 'icon', 'keywords', 'fields'], true)) {
                    $errors[] = "cards.provides.{$id} can't have {$key}.";
                }
            }

            if (! is_string($card['label'] ?? null) || trim($card['label']) === '' || mb_strlen($card['label']) > 40) {
                $errors[] = "cards.provides.{$id} needs a label of 1 to 40 characters.";
            }

            if (! is_string($card['description'] ?? null) || trim($card['description']) === '' || mb_strlen($card['description']) > 160) {
                $errors[] = "cards.provides.{$id} needs a description of 1 to 160 characters.";
            }

            if (array_key_exists('icon', $card) && (! is_string($card['icon']) || preg_match(ThemeCardNode::ID, $card['icon']) !== 1)) {
                $errors[] = "cards.provides.{$id} names its icon in lower-case letters, digits and hyphens.";
            }

            if (array_key_exists('keywords', $card) && (! is_array($card['keywords']) || ! array_is_list($card['keywords']) || count($card['keywords']) > 10 || array_any($card['keywords'], fn (mixed $word): bool => ! is_string($word) || trim($word) === '' || mb_strlen($word) > 40))) {
                $errors[] = "cards.provides.{$id} has at most 10 keywords of 1 to 40 characters.";
            }

            foreach (app(ContributionsValidator::class)->cardFieldErrors($card['fields'] ?? [], $version, choice: false) as $error) {
                $errors[] = "cards.provides.{$id}: {$error}";
            }

            if (! is_file("{$themePath}/views/cards/own/{$id}.blade.php")) {
                $errors[] = "cards.provides.{$id} needs views/cards/own/{$id}.blade.php.";
            }
        }

        return $errors;
    }

    /**
     * An optional https address of at most 2000 characters, naming the key when wrong.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function httpsAddress(array $manifest, string $key): array
    {
        if (! array_key_exists($key, $manifest)) {
            return [];
        }

        $value = $manifest[$key];

        if (! is_string($value) || strlen($value) > 2000 || ! str_starts_with(strtolower($value), 'https://') || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return ["{$key} must be an https address of at most 2000 characters."];
        }

        return [];
    }

    private function show(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}

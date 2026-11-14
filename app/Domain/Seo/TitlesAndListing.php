<?php

declare(strict_types=1);

namespace App\Domain\Seo;

use App\Domain\Addresses\TypeArchives;
use App\Domain\Settings\SettingsCheck;
use App\Domain\Site\Head\TitleTemplate;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\Manifest;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Search and social's section "Titles, and what search engines may list"
 * (docs/studies/seo.md §3.14): the title separator, and a title template and
 * hide box for each public content type and taxonomy, each type's archive
 * page, and each kind of archive, made from the registries, so a type registered later gets its own
 * rows (I-853). The home page and "page not found" have no hide box (I-854),
 * and search results are always hidden. Its own check refuses a template that
 * names a token Teme hasn't got.
 */
final class TitlesAndListing implements SearchAndSocialSection, SettingsCheck
{
    /** An item's title, then the site's (I-852). */
    public const string ITEM = '{title} {sep} {site}';

    /** An archive's own heading, its page from page 2, then the site's. */
    public const string ARCHIVE = '{title} {sep} {page} {sep} {site}';

    /** The site's address: its name, its page from page 2, its tagline. */
    public const string HOME = '{site} {sep} {page} {sep} {tagline}';

    public function order(): int
    {
        return 100;
    }

    public function key(): string
    {
        return 'titles-and-listing';
    }

    public function label(): string
    {
        return 'Titles, and what search engines may list';
    }

    public function settings(): array
    {
        $since = '0.1.0';
        $template = 'The title search results and browser tabs show for each one, made from tokens such as {title}, {sep} and {site}. An item’s own SEO title takes its place, word for word.';
        $settings = [Setting::text('title_separator', 'Separator', 'Goes between the parts of every title. Empty uses the one the active theme suggests.', $since)->maxLength(5)];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if (! $type->public) {
                continue;
            }

            $plural = mb_strtolower($type->plural);
            $settings[] = Setting::text("title_type_{$type->key}", "Title template for {$plural}", $template, $since)->default(self::ITEM)->maxLength(200);
            $settings[] = Setting::boolean("hide_type_{$type->key}", "Hide {$plural} from search engines", 'Marks every one noindex, so search engines leave them out. One item can also be hidden on its own, in the editor.', $since);
        }

        $settings[] = Setting::text('title_home', 'Title template for the home page', 'The title of the site’s address. {page} gives “Page 2 of 7” from the second page on, and empty tokens and doubled separators are dropped.', $since)->default(self::HOME)->maxLength(200);

        foreach (TypeArchives::types() as $key) {
            $type = app(ContentTypeRegistry::class)->get($key);
            $settings[] = Setting::text("title_archive_{$key}", 'Title template for the '.mb_strtolower($type->plural).' archive page', 'The title of the page listing every published '.mb_strtolower($type->singular).'. {title} is “'.$type->plural.'”.', '0.2.0')->default(self::ARCHIVE)->maxLength(200);
            $settings[] = Setting::boolean("hide_archive_{$key}", 'Hide the '.mb_strtolower($type->plural).' archive page from search engines', 'Marks it noindex and leaves it out of the sitemap. Each '.mb_strtolower($type->singular).' follows its own row.', '0.2.0');
        }

        foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
            if (! $taxonomy->public) {
                continue;
            }

            $singular = mb_strtolower($taxonomy->singular);
            $settings[] = Setting::text("title_taxonomy_{$taxonomy->key}", "Title template for {$singular} archives", 'The title of each archive. {title} is the archive’s own name.', $since)->default(self::ARCHIVE)->maxLength(200);
            $settings[] = Setting::boolean("hide_taxonomy_{$taxonomy->key}", "Hide {$singular} archives from search engines", 'Marks every archive noindex, so search engines leave them out.', $since);
        }

        return [
            ...$settings,
            Setting::text('title_author', 'Title template for author archives', 'The title of each author’s archive. {title} is the author’s name.', $since)->default(self::ARCHIVE)->maxLength(200),
            Setting::boolean('hide_author', 'Hide author archives from search engines', 'Marks every author’s archive noindex, so search engines leave them out.', $since),
            Setting::text('title_date', 'Title template for date archives', 'The title of each year’s, month’s and day’s archive. {title} is the date.', $since)->default(self::ARCHIVE)->maxLength(200),
            Setting::boolean('hide_date', 'Hide date archives from search engines', 'Marks every date archive noindex, so search engines leave them out.', $since),
            Setting::text('title_search', 'Title template for search results', 'The title of a search’s results, which search engines are always asked not to list. {title} names the words searched; {search} gives the words alone, as plain text.', $since)->default(self::ARCHIVE)->maxLength(200),
            Setting::text('title_not_found', 'Title template for the “page not found” page', 'The title of the page shown for an address with nothing at it.', $since)->default(self::ITEM)->maxLength(200),
        ];
    }

    /**
     * The section's rows, in its settings' order: each kind of page with its
     * template's key and default, and its hide box's key, or null where the
     * row has none (the home page, search results, "page not found").
     *
     * @return list<array{key: string, label: string, template: string, default: string, hide: string|null}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if ($type->public) {
                $rows[] = ['key' => "type:{$type->key}", 'label' => $type->plural, 'template' => "title_type_{$type->key}", 'default' => self::ITEM, 'hide' => "hide_type_{$type->key}"];
            }
        }

        $rows[] = ['key' => 'home', 'label' => 'Home page', 'template' => 'title_home', 'default' => self::HOME, 'hide' => null];

        foreach (TypeArchives::types() as $key) {
            $type = app(ContentTypeRegistry::class)->get($key);
            $rows[] = ['key' => "archive:{$key}", 'label' => "{$type->plural} archive page", 'template' => "title_archive_{$key}", 'default' => self::ARCHIVE, 'hide' => "hide_archive_{$key}"];
        }

        foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
            if ($taxonomy->public) {
                $rows[] = ['key' => "taxonomy:{$taxonomy->key}", 'label' => "{$taxonomy->singular} archives", 'template' => "title_taxonomy_{$taxonomy->key}", 'default' => self::ARCHIVE, 'hide' => "hide_taxonomy_{$taxonomy->key}"];
            }
        }

        return [
            ...$rows,
            ['key' => 'author', 'label' => 'Author archives', 'template' => 'title_author', 'default' => self::ARCHIVE, 'hide' => 'hide_author'],
            ['key' => 'date', 'label' => 'Date archives', 'template' => 'title_date', 'default' => self::ARCHIVE, 'hide' => 'hide_date'],
            ['key' => 'search', 'label' => 'Search results', 'template' => 'title_search', 'default' => self::ARCHIVE, 'hide' => null],
            ['key' => 'not_found', 'label' => 'Page not found', 'template' => 'title_not_found', 'default' => self::ITEM, 'hide' => null],
        ];
    }

    public function group(): string
    {
        return SearchAndSocial::KEY;
    }

    public function problems(array $values): array
    {
        $problems = [];

        foreach (array_column($this->rows(), 'template') as $key) {
            $template = $values[$key] ?? null;

            if (! is_string($template) || preg_match_all('/\{([^{}]*)\}/', $template, $found) === false) {
                continue;
            }

            foreach ($found[1] as $token) {
                if (! in_array($token, TitleTemplate::TOKENS, true)) {
                    $problems["values.{$key}"] = 'Teme has no {'.$token.'} token. Use the tokens listed above.';

                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * The separator between a title's parts: the setting, trimmed, or else
     * the one the active theme suggests.
     */
    public static function separator(): string
    {
        $separator = trim((string) (self::values()['title_separator'] ?? ''));

        return $separator !== '' ? $separator : (app(ActiveTheme::class)->get()?->manifest?->titleSeparator() ?? Manifest::TITLE_SEPARATOR);
    }

    /**
     * A row's title template, or its default when the row is blank or was
     * registered after the settings were read.
     *
     * @param  string  $row  `type:{key}`, `taxonomy:{key}`, `home`, `author`, `date`, `search`, `not_found` or `archive:{key}`
     */
    public static function template(string $row): string
    {
        [$kind, $key] = array_pad(explode(':', $row, 2), 2, '');

        [$setting, $default] = match ($kind) {
            'type' => ["title_type_{$key}", self::ITEM],
            'taxonomy' => ["title_taxonomy_{$key}", self::ARCHIVE],
            'home' => ['title_home', self::HOME],
            'not_found' => ['title_not_found', self::ITEM],
            'archive' => ["title_archive_{$key}", self::ARCHIVE],
            default => ["title_{$kind}", self::ARCHIVE],
        };

        $template = self::values()[$setting] ?? null;

        return is_string($template) && trim($template) !== '' ? $template : $default;
    }

    /**
     * Whether every item of this type is hidden from search engines; false for a type with no row.
     */
    public static function hidesType(string $type): bool
    {
        return (self::values()["hide_type_{$type}"] ?? false) === true;
    }

    /**
     * Whether this type's archive page is hidden from search engines; false for a type with no row.
     */
    public static function hidesArchive(string $type): bool
    {
        return (self::values()["hide_archive_{$type}"] ?? false) === true;
    }

    /**
     * Whether this taxonomy's archives are hidden from search engines; false for one with no row.
     */
    public static function hidesTaxonomy(string $taxonomy): bool
    {
        return (self::values()["hide_taxonomy_{$taxonomy}"] ?? false) === true;
    }

    /**
     * Whether author archives are hidden from search engines.
     */
    public static function hidesAuthors(): bool
    {
        return (self::values()['hide_author'] ?? false) === true;
    }

    /**
     * Whether date archives are hidden from search engines.
     */
    public static function hidesDates(): bool
    {
        return (self::values()['hide_date'] ?? false) === true;
    }

    /**
     * Every value of Search and social, read through the settings, so the page carries the group's tag.
     *
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        return app(SettingsReader::class)->group(SearchAndSocial::KEY);
    }
}

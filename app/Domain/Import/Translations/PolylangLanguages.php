<?php

declare(strict_types=1);

namespace App\Domain\Import\Translations;

/**
 * What the old site's Polylang says of its languages, as the companion's
 * `languages` route sends it (docs/specs/companion-languages.md): whether it
 * is active, its default, every language with the Teme language it becomes,
 * how addresses carried the language, which types and taxonomies it
 * translates, its menus per language, its string translations, and the
 * other translation plugins the site has on. Read from JSON alone; it
 * never fails on an answer it can't read whole.
 */
final readonly class PolylangLanguages
{
    /** Polylang sets the language from the content: addresses carry none. */
    public const string FROM_CONTENT = 'content';

    /** Polylang sets the language from the address's first directory: /pt/. */
    public const string DIRECTORY = 'directory';

    /** Polylang sets the language from a subdomain: pt.example.com. */
    public const string SUBDOMAIN = 'subdomain';

    /** Polylang gives each language a domain of its own. */
    public const string DOMAIN = 'domain';

    /** Polylang's `force_lang` values, in its order. */
    private const array MODES = [self::FROM_CONTENT, self::DIRECTORY, self::SUBDOMAIN, self::DOMAIN];

    /**
     * @param  list<array{slug: string, locale: string, name: string, rtl: bool, language: string|null}>  $languages
     * @param  array<string, mixed>  $options
     * @param  list<string>  $postTypes
     * @param  list<string>  $taxonomies
     * @param  array<string, array<string, array<string, int>>>  $menus
     * @param  array<string, list<array{name: string, translation: string}>>  $strings
     * @param  array<string, array{name: string, languages: int}>  $others  plugin slug => its name and its languages
     */
    private function __construct(
        public bool $active,
        public string $default,
        public array $languages,
        public array $options,
        public array $postTypes,
        public array $taxonomies,
        public array $menus,
        public array $strings,
        public array $others = [],
    ) {}

    /**
     * A site without Polylang, or a companion too old to say, with the
     * other translation plugins it has on.
     *
     * @param  array<string, array{name: string, languages: int}>  $others
     */
    public static function none(array $others = []): self
    {
        return new self(false, '', [], [], [], [], [], [], $others);
    }

    /**
     * The companion's answer, read: languages without a slug or a locale
     * are left out, and a default that names none of them makes it read as
     * a site without Polylang.
     *
     * @param  array<mixed>  $answer
     */
    public static function fromAnswer(array $answer): self
    {
        $others = [];

        foreach (self::map($answer['others'] ?? null) as $plugin => $other) {
            $other = self::map($other);

            if (is_string($plugin) && $plugin !== '') {
                $name = self::text($other['name'] ?? null);
                $others[$plugin] = ['name' => $name !== '' ? $name : $plugin, 'languages' => is_numeric($other['languages'] ?? null) ? max(0, (int) $other['languages']) : 0];
            }
        }

        if (($answer['active'] ?? false) !== true) {
            return self::none($others);
        }

        $languages = [];

        foreach (self::list($answer['languages'] ?? null) as $index => $language) {
            $slug = self::text($language['slug'] ?? null);
            $locale = self::text($language['locale'] ?? null);

            if ($slug === '' || $locale === '' || isset($languages[$slug])) {
                continue;
            }

            $languages[$slug] = [
                'slug' => $slug,
                'locale' => $locale,
                'name' => self::text($language['name'] ?? null),
                'rtl' => ($language['rtl'] ?? false) === true,
                'language' => self::tagFor($locale),
                'order' => is_numeric($language['order'] ?? null) ? (int) $language['order'] : $index,
            ];
        }

        uasort($languages, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $default = self::text($answer['default'] ?? null);

        if (! isset($languages[$default])) {
            return self::none($others);
        }

        $menus = [];

        foreach (self::map($answer['menus'] ?? null) as $theme => $locations) {
            foreach (self::map($locations) as $location => $byLanguage) {
                foreach (self::map($byLanguage) as $slug => $menu) {
                    if (is_numeric($menu) && (int) $menu > 0) {
                        $menus[(string) $theme][(string) $location][(string) $slug] = (int) $menu;
                    }
                }
            }
        }

        $strings = [];

        foreach (self::map($answer['strings'] ?? null) as $slug => $pairs) {
            foreach (self::list($pairs) as $pair) {
                $name = self::text($pair['name'] ?? null);

                if ($name !== '' && is_string($pair['translation'] ?? null)) {
                    $strings[(string) $slug][] = ['name' => $name, 'translation' => $pair['translation']];
                }
            }
        }

        return new self(
            true,
            $default,
            array_values(array_map(fn (array $language): array => array_diff_key($language, ['order' => true]), $languages)),
            self::map($answer['options'] ?? null),
            self::words($answer['post_types'] ?? null),
            self::words($answer['taxonomies'] ?? null),
            $menus,
            $strings,
            $others,
        );
    }

    /**
     * The Teme language a WordPress locale becomes, or null when Teme
     * offers none: the language alone where the locale is its main
     * country's (es_ES, pt_PT, en_US), the language and its country where
     * Teme offers that (pt_BR, en_GB), else the language alone (ja).
     */
    public static function tagFor(string $locale): ?string
    {
        $parts = explode('_', str_replace('-', '_', trim($locale)));
        $alone = strtolower($parts[0]);
        $offered = (array) config('languages');

        if ($alone === '') {
            return null;
        }

        $region = isset($parts[1]) && $parts[1] !== '' ? strtoupper($parts[1]) : null;
        $main = is_array($offered[$alone] ?? null) ? ($offered[$alone]['og'] ?? null) : null;

        if ($region !== null && $main !== $alone.'_'.$region && array_key_exists($alone.'-'.$region, $offered)) {
            return $alone.'-'.$region;
        }

        return array_key_exists($alone, $offered) ? $alone : null;
    }

    /**
     * The language with this Polylang slug, or null.
     *
     * @return array{slug: string, locale: string, name: string, rtl: bool, language: string|null}|null
     */
    public function language(string $slug): ?array
    {
        foreach ($this->languages as $language) {
            if ($language['slug'] === $slug) {
                return $language;
            }
        }

        return null;
    }

    /**
     * The Teme language the default becomes, or null when Teme offers none.
     */
    public function defaultTag(): ?string
    {
        return $this->language($this->default)['language'] ?? null;
    }

    /**
     * How Polylang told a page's language: from the content, a directory,
     * a subdomain or a domain; a directory when it doesn't say.
     */
    public function mode(): string
    {
        $value = $this->options['force_lang'] ?? 1;

        return self::MODES[is_numeric($value) ? (int) $value : 1] ?? self::DIRECTORY;
    }

    /**
     * Whether Polylang translates this WordPress post type.
     */
    public function translatesType(string $wordpressType): bool
    {
        return in_array($wordpressType, $this->postTypes, true);
    }

    /**
     * Whether Polylang translates this WordPress taxonomy.
     */
    public function translatesTaxonomy(string $wordpressTaxonomy): bool
    {
        return in_array($wordpressTaxonomy, $this->taxonomies, true);
    }

    /**
     * The menu places of the old site's theme, each with its menu per
     * language: Polylang keeps them for every theme the site has had, so
     * the theme whose default-language menus are the ones WordPress shows
     * now, else the first.
     *
     * @param  array<string, int>  $assigned  WordPress's menu locations, location => menu ID
     *
     * @return array<string, array<string, int>> location => language slug => menu ID
     */
    public function menuPlaces(array $assigned): array
    {
        $best = null;
        $score = -1;

        foreach ($this->menus as $places) {
            $matches = 0;

            foreach ($assigned as $location => $menu) {
                $matches += ($places[$location][$this->default] ?? null) === $menu ? 1 : 0;
            }

            if ($matches > $score) {
                [$best, $score] = [$places, $matches];
            }
        }

        return $best ?? [];
    }

    /**
     * The Polylang slug of the language a menu is placed for, in any theme
     * the site has had, or null when none places it.
     */
    public function menuLanguage(int $menuId): ?string
    {
        foreach ($this->menus as $places) {
            foreach ($places as $byLanguage) {
                $slug = array_search($menuId, $byLanguage, true);

                if (is_string($slug)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /**
     * Returns the answer as it is kept between jobs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'active' => $this->active,
            'default' => $this->default,
            'languages' => array_map(fn (array $language): array => array_diff_key($language, ['language' => true]), $this->languages),
            'options' => $this->options,
            'post_types' => $this->postTypes,
            'taxonomies' => $this->taxonomies,
            'menus' => $this->menus,
            'strings' => $this->strings,
            'others' => $this->others,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        return array_values(array_filter(self::map($value), is_array(...)));
    }

    /**
     * @return list<string>
     */
    private static function words(mixed $value): array
    {
        return array_values(array_unique(array_filter(self::map($value), fn (mixed $word): bool => is_string($word) && $word !== '')));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

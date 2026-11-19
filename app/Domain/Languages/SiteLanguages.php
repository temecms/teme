<?php

/**
 * The site's languages (I-3360): the default, `general.language`, then the
 * others the `languages` settings group adds, each with its address prefix
 * and whether readers are shown it, and which types and taxonomies are
 * translated. The one place a language's tag becomes the stored value (null
 * for the default) and back. Every answer is read through the settings
 * memo, so a page that asks records the settings tag.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages;

use App\Domain\Site\SiteLanguage;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Teme\Contracts\Settings\SettingsReader;

final readonly class SiteLanguages
{
    public const string GROUP = 'languages';

    public function __construct(private SettingsReader $settings, private SiteLanguage $site) {}

    /**
     * The default language's tag: the site language, `general.language`.
     */
    public function defaultTag(): string
    {
        return $this->site->tag();
    }

    /**
     * The other languages' tags, in the order they were added; a tag
     * config/languages.php no longer lists is left out, so a config change
     * never breaks a page.
     *
     * @return list<string>
     */
    public function others(): array
    {
        $chosen = $this->settings->stringList(self::GROUP, 'others');
        $known = (array) config('languages');
        $default = $this->defaultTag();
        $ordered = [];

        // The prefixes' rows keep the order they were added in; the
        // multiselect keeps the config's order, so it says only which.
        foreach ([...array_keys($this->prefixes()), ...$chosen] as $tag) {
            if (in_array($tag, $chosen, true) && array_key_exists($tag, $known) && $tag !== $default && ! in_array($tag, $ordered, true)) {
                $ordered[] = $tag;
            }
        }

        return $ordered;
    }

    /**
     * Every language of the site: the default first, then the others.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return [$this->defaultTag(), ...$this->others()];
    }

    /**
     * The languages readers are shown: all() without the hidden ones.
     *
     * @return list<string>
     */
    public function shown(): array
    {
        $hidden = $this->settings->stringList(self::GROUP, 'hidden');

        return array_values(array_filter($this->all(), fn (string $tag): bool => $tag === $this->defaultTag() || ! in_array($tag, $hidden, true)));
    }

    /**
     * The other languages readers get nothing in.
     *
     * @return list<string>
     */
    public function hidden(): array
    {
        return array_values(array_diff($this->others(), $this->shown()));
    }

    /**
     * The query narrowed to rows readers may see in: the default language's
     * and the shown ones' (D7); unchanged while no language is hidden.
     *
     * @template TQuery of QueryBuilder
     *
     * @param  TQuery  $query
     *
     * @return TQuery
     */
    public function whereShown(QueryBuilder $query, string $column = 'language'): QueryBuilder
    {
        $hidden = $this->hidden();

        if ($hidden !== []) {
            $query->where(fn (QueryBuilder $shown) => $shown->whereNull($column)->orWhereNotIn($column, $hidden));
        }

        return $query;
    }

    /**
     * A raw statement's condition keeping to the default language's rows,
     * after an AND, for a part that reads no other language yet (A5): none
     * while the site has one language, whose every row is the default's,
     * since a language is removed only once it holds none.
     */
    public function defaultOnlySql(string $column): string
    {
        return $this->isMultilingual() ? " AND {$column} IS NULL" : '';
    }

    /**
     * A query kept to the default language's rows, as defaultOnlySql() keeps a raw statement.
     *
     * @template TQuery of QueryBuilder
     *
     * @param  TQuery  $query
     *
     * @return TQuery
     */
    public function whereDefault(QueryBuilder $query, string $column): QueryBuilder
    {
        if ($this->isMultilingual()) {
            $query->whereNull($column);
        }

        return $query;
    }

    /**
     * Whether a site path (no leading slash) lies under a hidden language's
     * prefix: every address of an item or term in another language carries
     * its prefix (D6), so its path alone says whether readers may see it.
     */
    public function isHiddenPath(string $path): bool
    {
        $hidden = $this->hidden();

        if ($hidden === []) {
            return false;
        }

        $tag = $this->byPrefix(explode('/', ltrim($path, '/'), 2)[0]);

        return $tag !== null && in_array($tag, $hidden, true);
    }

    /**
     * Whether the tag is one of the site's languages, shown or hidden.
     */
    public function has(string $tag): bool
    {
        return in_array($tag, $this->all(), true);
    }

    /**
     * Whether readers are shown a stored language: null, the default, always.
     */
    public function isShown(?string $stored): bool
    {
        return $stored === null || in_array($stored, $this->shown(), true);
    }

    /**
     * The value a writer stores for a tag: null for the default language,
     * the tag for any other of the site's (I-3360).
     *
     * @throws InvalidArgumentException for a tag that is not one of the site's languages
     */
    public function stored(string $tag): ?string
    {
        if ($tag === $this->defaultTag()) {
            return null;
        }

        if (! in_array($tag, $this->others(), true)) {
            throw new InvalidArgumentException("[{$tag}] is not one of the site's languages.");
        }

        return $tag;
    }

    /**
     * The tag a stored value stands for: null is the default language.
     */
    public function of(?string $stored): string
    {
        return $stored ?? $this->defaultTag();
    }

    /**
     * The language's first part of every address: '' for the default, else
     * its own setting, the lower-case tag when it has none.
     */
    public function prefix(string $tag): string
    {
        if ($tag === $this->defaultTag()) {
            return '';
        }

        return $this->prefixes()[$tag] ?? mb_strtolower($tag);
    }

    /**
     * The other language whose prefix the segment is, shown or hidden, or
     * null; never the default, which has none.
     */
    public function byPrefix(string $segment): ?string
    {
        if ($segment === '') {
            return null;
        }

        foreach ($this->others() as $tag) {
            if ($this->prefix($tag) === $segment) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Whether the site publishes in any language beside its default.
     */
    public function isMultilingual(): bool
    {
        return $this->others() !== [];
    }

    /**
     * Whether items of the type can be in other languages: every type is,
     * until it is named as not translated.
     */
    public function translates(string $type): bool
    {
        return ! in_array($type, $this->settings->stringList(self::GROUP, 'untranslated_types'), true);
    }

    /**
     * Whether terms of the taxonomy can be in other languages: every one
     * is, until it is named as not translated.
     */
    public function translatesTaxonomy(string $taxonomy): bool
    {
        return ! in_array($taxonomy, $this->settings->stringList(self::GROUP, 'untranslated_taxonomies'), true);
    }

    /**
     * The language's English name, such as "French".
     */
    public function label(string $tag): string
    {
        return $this->entry($tag, 'label') ?? $tag;
    }

    /**
     * The language's own name, such as "Français".
     */
    public function native(string $tag): string
    {
        return $this->entry($tag, 'native') ?? $this->label($tag);
    }

    /**
     * The direction its text runs: 'ltr' or 'rtl'.
     */
    public function direction(string $tag): string
    {
        return $this->entry($tag, 'direction') === 'rtl' ? 'rtl' : 'ltr';
    }

    /**
     * The language's locale in PHP's form, such as "pt_BR".
     */
    public function locale(string $tag): string
    {
        return str_replace('-', '_', $tag);
    }

    /**
     * The language's Open Graph locale, such as "fr_FR".
     */
    public function ogLocale(string $tag): string
    {
        return $this->entry($tag, 'og') ?? $this->locale($tag);
    }

    /**
     * Each other language's prefix as stored, by tag, in the order added.
     *
     * @return array<string, string>
     */
    private function prefixes(): array
    {
        $prefixes = [];
        $rows = $this->settings->get(self::GROUP, 'prefixes');

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['name'] ?? null) && is_string($row['value'] ?? null) && $row['value'] !== '') {
                $prefixes[$row['name']] = $row['value'];
            }
        }

        return $prefixes;
    }

    private function entry(string $tag, string $key): ?string
    {
        $language = ((array) config('languages'))[$tag] ?? null;

        return is_array($language) && is_string($language[$key] ?? null) ? $language[$key] : null;
    }
}

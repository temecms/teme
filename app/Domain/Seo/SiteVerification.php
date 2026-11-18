<?php

/**
 * Search and social's section "Site verification" (docs/studies/seo.md
 * §3.12): name and content pairs that services such as Google Search
 * Console ask a site to show, each written on the home page as an escaped
 * meta tag, never as markup (I-288). Its own check refuses a name that isn't
 * a meta tag's, or one Teme writes itself, since the site's entry would win
 * over core's.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Seo;

use App\Domain\Settings\SettingsCheck;
use App\Domain\Site\Document\MetaTag;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

final class SiteVerification implements SearchAndSocialSection, SettingsCheck
{
    public const string TAGS = 'site_verification';

    /** The names Teme writes itself, lower-cased: core's head entries and the shell's viewport. */
    public const array RESERVED = ['description', 'robots', 'twitter:card', 'viewport'];

    public const int MOST = 20;

    public const int LONGEST = 500;

    public function order(): int
    {
        return 500;
    }

    public function key(): string
    {
        return 'site-verification';
    }

    public function label(): string
    {
        return 'Site verification';
    }

    public function settings(): array
    {
        return [
            Setting::pairs(self::TAGS, 'Site verification', 'One name and content pair per service.', '0.1.0', 'Name', 'Content')
                ->max(self::MOST)->maxLength(self::LONGEST),
        ];
    }

    public function group(): string
    {
        return SearchAndSocial::KEY;
    }

    public function problems(array $values): array
    {
        $rows = $values[self::TAGS] ?? null;

        // Anything but a list of rows is the pairs type's own to refuse.
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }

        // Each name's first row, lower-cased as the head compares names.
        $seen = [];

        foreach ($rows as $index => $row) {
            [$name, $content] = self::parts($row);

            // A row with both parts empty is dropped when stored.
            if ($name === '' && $content === '') {
                continue;
            }

            $problem = self::problem($name, $content)
                ?? (isset($seen[strtolower($name)]) ? 'row '.$seen[strtolower($name)].' has this name already, and a page carries one tag of each name.' : null);

            if ($problem !== null) {
                return ['values.'.self::TAGS => 'Row '.($index + 1).': '.$problem];
            }

            $seen[strtolower($name)] = $index + 1;
        }

        return [];
    }

    /**
     * The tags the head may write: each stored row that passes the checks,
     * the first of each name, since an import could store one that doesn't.
     *
     * @return list<array{name: string, value: string}>
     */
    public static function tags(): array
    {
        $rows = app(SettingsReader::class)->get(SearchAndSocial::KEY, self::TAGS);
        $tags = [];
        $seen = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            [$name, $content] = self::parts($row);

            if (self::problem($name, $content) === null && ! isset($seen[strtolower($name)])) {
                $seen[strtolower($name)] = true;
                $tags[] = ['name' => $name, 'value' => $content];
            }
        }

        return $tags;
    }

    /**
     * A row's name and content, trimmed, '' for anything that isn't text.
     *
     * @return array{0: string, 1: string}
     */
    private static function parts(mixed $row): array
    {
        $name = is_array($row) && is_string($row['name'] ?? null) ? trim($row['name']) : '';
        $content = is_array($row) && is_string($row['value'] ?? null) ? trim($row['value']) : '';

        return [$name, $content];
    }

    /**
     * What is wrong with one tag, in the words after "Row {n}: ", or null.
     */
    private static function problem(string $name, string $content): ?string
    {
        return match (true) {
            $name === '' => 'give the tag its name, such as google-site-verification.',
            preg_match(MetaTag::KEY_PATTERN, $name) !== 1 || strlen($name) > 100 => 'use letters and numbers in the name, joined by dots, hyphens, colons or underscores, as in google-site-verification.',
            in_array(strtolower($name), self::RESERVED, true) => 'Teme writes the '.$name.' tag itself, so it can’t be a verification tag.',
            $content === '' => 'paste the content the service gave you.',
            // What Google shows people is the whole tag; only its content belongs here.
            str_contains($content, '<') => 'paste only the text inside content="…", not the whole tag.',
            default => null,
        };
    }
}

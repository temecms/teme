<?php

declare(strict_types=1);

namespace App\Domain\Import\Translations;

/**
 * What the analyze step says of an old site's languages before an import
 * starts (I-3446): Polylang addresses Teme can't keep, the default
 * language's first part of the address, and the other translation
 * plugins whose translations v1 doesn't import.
 */
final class LanguageNotes
{
    /**
     * The rows the analysis lists under what doesn't come across: none for
     * a site without translation plugins, or with Polylang by directory.
     *
     * @return list<array{key: string, wordpressKey: string|null, label: string, count: int, why: string}>
     */
    public static function stays(PolylangLanguages $languages): array
    {
        $rows = [];
        $count = count($languages->languages);

        if ($languages->active && $languages->mode() === PolylangLanguages::FROM_CONTENT) {
            $rows[] = ['key' => 'languages.from-content', 'wordpressKey' => 'polylang', 'label' => __('Polylang'), 'count' => $count, 'why' => __('Polylang sets each page’s language from its content, so its addresses don’t say which language they are in, and the import stops before it writes anything. Set Polylang’s URL modifications to “The language is set from the directory name” first.')];
        }

        if ($languages->active && in_array($languages->mode(), [PolylangLanguages::SUBDOMAIN, PolylangLanguages::DOMAIN], true)) {
            $rows[] = ['key' => 'languages.per-host', 'wordpressKey' => 'polylang', 'label' => __('Polylang’s subdomains or domains'), 'count' => $count, 'why' => __('Each language comes across, with a first part of the address, such as /pt/, instead of its own subdomain or domain: add redirects from the old hosts to this site.')];
        }

        if ($languages->active && $languages->mode() === PolylangLanguages::DIRECTORY && ($languages->options['hide_default'] ?? true) === false) {
            $default = $languages->language($languages->default);
            $rows[] = ['key' => 'languages.default-prefixed', 'wordpressKey' => 'polylang', 'label' => __('Polylang’s /:slug/ for the default language', ['slug' => $languages->default]), 'count' => 1, 'why' => __('Polylang showed the default language, :name, at /:slug/. Teme gives the default language no first part of the address, so its posts and pages come across without it, and their old addresses lead to the new ones.', ['name' => $default['name'] ?? $languages->default, 'slug' => $languages->default])];
        }

        foreach ($languages->others as $plugin => $other) {
            $rows[] = ['key' => 'languages.other.'.$plugin, 'wordpressKey' => $plugin, 'label' => $other['name'], 'count' => $other['languages'], 'why' => __('Teme can’t import this plugin’s translations yet, only Polylang’s: only this site’s own posts and pages come across, in its default language.')];
        }

        return $rows;
    }
}

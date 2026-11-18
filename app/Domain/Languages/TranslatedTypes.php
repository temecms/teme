<?php

/**
 * The refusals for a type or a taxonomy that isn't translated (D25, I-3370):
 * asked in validate() from the settings, and again in perform(), under the
 * languages' lock, from the row, so a change that committed meanwhile shows.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages;

use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Validation\ValidationException;

final class TranslatedTypes
{
    /**
     * Refuses a version in another language, or a link, for a content type
     * that isn't translated.
     *
     * @throws ValidationException
     */
    public static function refuseType(string $type, bool $fromRow = false, string $key = 'language'): void
    {
        $translated = $fromRow ? ! in_array($type, self::row('untranslated_types'), true) : app(SiteLanguages::class)->translates($type);

        if (! $translated) {
            $types = app(ContentTypeRegistry::class);
            $plural = $types->has($type) ? $types->get($type)->plural : $type;

            throw ValidationException::withMessages([$key => __(":plural aren't translated on this site. Turn translation on for them in Settings › Languages first.", ['plural' => $plural])]);
        }
    }

    /**
     * Refuses a term in another language for a taxonomy that isn't translated.
     *
     * @throws ValidationException
     */
    public static function refuseTaxonomy(string $taxonomy, bool $fromRow = false, string $key = 'language'): void
    {
        $translated = $fromRow ? ! in_array($taxonomy, self::row('untranslated_taxonomies'), true) : app(SiteLanguages::class)->translatesTaxonomy($taxonomy);

        if (! $translated) {
            $taxonomies = app(TaxonomyRegistry::class);
            $plural = $taxonomies->has($taxonomy) ? $taxonomies->get($taxonomy)->plural : $taxonomy;

            throw ValidationException::withMessages([$key => __(":plural aren't translated on this site, so their terms are shared by every language.", ['plural' => $plural])]);
        }
    }

    /**
     * The taxonomies that aren't translated, as the settings hold them.
     *
     * @return list<string>
     */
    public static function untranslatedTaxonomies(): array
    {
        $languages = app(SiteLanguages::class);
        $keys = array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, app(TaxonomyRegistry::class)->all());

        return array_values(array_filter($keys, fn (string $key): bool => ! $languages->translatesTaxonomy($key)));
    }

    /**
     * The taxonomies that aren't translated, read from the languages' row
     * under the languages' lock, not the settings' cache filled before it.
     *
     * @return list<string>
     */
    public static function sharedTaxonomies(): array
    {
        return self::row('untranslated_taxonomies');
    }

    /**
     * One list of the languages' row, read from the row, not the settings' cache.
     *
     * @return list<string>
     */
    private static function row(string $key): array
    {
        $overrides = (array) SettingsGroupRecord::query()->whereKey(SiteLanguages::GROUP)->first()?->overrides;

        return array_values(array_filter((array) ($overrides[$key] ?? []), is_string(...)));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Languages;

use App\Domain\Cache\PageTags;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * The `languages` settings group (I-3360): the site's other languages, their
 * prefixes, which are hidden from readers, and which types and taxonomies
 * are not translated. It has its own screen, Settings › Languages, so only
 * its own Operations write it; built from the registries when read.
 */
final class LanguagesGroup
{
    /**
     * The group for the languages config/languages.php lists and the types
     * and taxonomies registered now.
     */
    public static function group(ContentTypeRegistry $types, TaxonomyRegistry $taxonomies): SettingsGroup
    {
        $languages = array_map(fn (array $language): string => (string) $language['label'], (array) config('languages'));
        $typeOptions = collect($types->all())->mapWithKeys(fn (ContentType $type): array => [$type->key => $type->plural])->all();
        $taxonomyOptions = collect($taxonomies->all())->mapWithKeys(fn (Taxonomy $taxonomy): array => [$taxonomy->key => $taxonomy->plural])->all();

        return new SettingsGroup(
            key: SiteLanguages::GROUP,
            label: 'Languages',
            description: 'The language your site is written in, and the other languages it publishes in.',
            section: 'general',
            ability: 'settings.general.manage',
            settings: [
                Setting::multiselect('others', 'Other languages', 'The languages beside the default one, in the order they were added.', '0.6.0', $languages),
                Setting::pairs('prefixes', 'Address prefixes', 'Each other language\'s first part of every address.', '0.6.0', 'Language', 'Prefix'),
                Setting::multiselect('hidden', 'Hidden from readers', 'Other languages readers get nothing in, while they are translated.', '0.6.0', $languages),
                Setting::multiselect('untranslated_types', 'Content types not translated', 'Content types whose items are always in the default language.', '0.6.0', $typeOptions),
                Setting::multiselect('untranslated_taxonomies', 'Taxonomies not translated', 'Taxonomies whose terms are always in the default language, shared by every language.', '0.6.0', $taxonomyOptions),
            ],
            since: '0.6.0',
            cacheTags: [PageTags::SETTINGS],
            screen: 'settings.languages',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Addresses\ListingTypes;
use App\Domain\Cache\PageTags;
use App\Domain\Types\ContentTypeRegistry;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * The `reading` settings group: how many items a listing page shows, and
 * which types the home page and the main feed list (I-3298), offered from
 * the public types with an archive and built again when the types change.
 * Its page also draws the addresses group's Home page.
 */
final class ReadingSettingsGroup
{
    public static function build(ContentTypeRegistry $types): SettingsGroup
    {
        $options = [];

        foreach (ListingTypes::withArchive() as $key) {
            $options[$key] = $types->get($key)->plural;
        }

        return new SettingsGroup(
            key: 'reading',
            label: 'Reading',
            description: 'What the home page shows, and how many items each listing page holds.',
            section: 'reading',
            ability: 'settings.general.manage',
            settings: [
                Setting::number('posts_per_page', 'Items per page', 'How many items each page of a listing shows: the latest posts, an archive or search results. 0 uses the theme’s own number.', '0.1.0')
                    ->default(0)->min(0)->max(100),
                // Posts, the first, ticked: every site lists what it listed before (I-3298).
                Setting::multiselect(ListingTypes::SETTING, 'Types on the home page and in the main feed', 'The types whose newest items the home page lists (or the posts page, when the home page is a page) and the site’s main feed carries. An archive of a category, tag or other term lists every type filed under it, ticked or not.', '0.2.0', $options)
                    ->default(array_slice(array_keys($options), 0, 1)),
            ],
            since: '0.1.0',
            cacheTags: [PageTags::SETTINGS],
        );
    }
}

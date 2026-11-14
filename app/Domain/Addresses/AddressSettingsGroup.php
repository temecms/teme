<?php

declare(strict_types=1);

namespace App\Domain\Addresses;

use App\Domain\Cache\PageTags;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * The `addresses` settings group, built from the type and taxonomy
 * registries (study Y §4.1): a pattern per public type, each type's archive
 * page, and a base per public taxonomy, with the defaults each declares,
 * then the author base and the home page. It has its own screen, Settings › Addresses.
 */
final class AddressSettingsGroup
{
    /**
     * The group for the types and taxonomies registered now. No list of keys
     * lives here: a type registered later brings its own default.
     */
    public static function build(ContentTypeRegistry $types, TaxonomyRegistry $taxonomies): SettingsGroup
    {
        $patterns = collect($types->all())->filter(fn (ContentType $type): bool => $type->public)
            ->map(fn (ContentType $type): Setting => Setting::text('pattern_'.$type->key, $type->singular.' address', 'The shape of every '.mb_strtolower($type->singular).'\'s address.', '0.1.0')
                ->default($type->pattern ?? '/'.$type->segment.'/{slug}/')->maxLength(200));
        $archives = collect(TypeArchives::types())->map(function (string $key) use ($types): Setting {
            $type = $types->get($key);

            return Setting::text('archive_'.$key, $type->plural.' archive page', 'The address of the page listing every published '.mb_strtolower($type->singular).', newest first. Its feed is this address followed by feed/.', '0.2.0')
                ->default(TypeArchives::defaultAddress($type))->maxLength(60);
        });
        $bases = collect($taxonomies->all())->filter(fn (Taxonomy $taxonomy): bool => $taxonomy->public)
            ->map(fn (Taxonomy $taxonomy): Setting => Setting::text('base_'.$taxonomy->key, $taxonomy->singular.' base', 'The word before a '.mb_strtolower($taxonomy->singular).'\'s address. Empty puts them at the root.', '0.1.0')
                ->default($taxonomy->base ?? $taxonomy->key)->maxLength(60));

        return new SettingsGroup(
            key: 'addresses',
            label: 'Addresses',
            description: 'The public addresses of every public type, taxonomy and author, and what the home page shows.',
            section: 'site',
            ability: 'settings.addresses.manage',
            settings: [
                ...$patterns->values()->all(),
                ...$archives->values()->all(),
                ...$bases->values()->all(),
                Setting::text('author_base', 'Author base', 'The word before an author\'s address.', '0.1.0')->default('author')->maxLength(60),
                Setting::select('front_shows', 'Home page shows', 'Your latest posts, or one page.', '0.1.0', [AddressSettings::FRONT_LATEST => 'Latest posts', AddressSettings::FRONT_PAGE => 'A page'])->default(AddressSettings::FRONT_LATEST),
                Setting::text('front_page_id', 'Home page', 'The page shown as the home page.', '0.1.0')->default(''),
                Setting::text('posts_page_id', 'Posts page', 'The page that lists posts when the home page is a page.', '0.1.0')->default(''),
            ],
            since: '0.1.0',
            cacheTags: [PageTags::EVERYTHING],
            screen: 'settings.addresses',
        );
    }
}

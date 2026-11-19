<?php

/**
 * The names the admin menu already gives its screens, which no content type
 * or taxonomy may take as its plural: the fixed screens, every entry the menu
 * registers, and the other types’ and taxonomies’ own lists.
 * Compared without case; a type stored with such a name keeps working and is
 * shown with a warning to rename it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Navigation\NavigationRegistry;
use Throwable;

final class AdminScreenNames
{
    /** The fixed screens' names, known before the menu is built at boot. */
    public const array FIXED = ['Home', 'Dashboard', 'Media', 'Comments', 'Navigation', 'Regions', 'People', 'Plugins', 'Themes', 'Types', 'Taxonomies', 'Settings', 'Tools'];

    /**
     * The admin screen $plural would share its name with, as the menu names
     * it, or null when none. A type or taxonomy is never compared with itself:
     * $typeKey or $taxonomyKey names it.
     *
     * @param  list<string>|null  $typePlurals  the other listed types' plurals, when the registries can't be asked yet, as at boot
     */
    public static function clash(string $plural, ?string $typeKey = null, ?string $taxonomyKey = null, ?array $typePlurals = null): ?string
    {
        $wanted = self::fold($plural);

        if ($wanted === '') {
            return null;
        }

        foreach (self::screens($typeKey, $taxonomyKey, $typePlurals) as $screen) {
            if (self::fold($screen) === $wanted) {
                return $screen;
            }
        }

        return null;
    }

    /**
     * The words a refusal or a warning gives: which screen it clashes with,
     * and a name it could take instead.
     */
    public static function sentence(string $screen, string $singular, string $noun): string
    {
        return __('The admin menu already has :screen. Give the :noun another plural name, such as “:suggestion”.', [
            'screen' => $screen,
            'noun' => $noun,
            'suggestion' => self::suggestion($singular, $screen),
        ]);
    }

    /**
     * A name that doesn't clash: the singular with "directory" after it, as
     * "Plugin directory" for Plugins.
     */
    public static function suggestion(string $singular, string $screen): string
    {
        $base = trim($singular) !== '' && self::fold($singular) !== self::fold($screen) ? trim($singular) : rtrim($screen, 's');

        return __(':name directory', ['name' => $base]);
    }

    /**
     * @param  list<string>|null  $typePlurals
     *
     * @return list<string>
     */
    private static function screens(?string $typeKey, ?string $taxonomyKey, ?array $typePlurals): array
    {
        $screens = self::FIXED;

        try {
            foreach (app(NavigationRegistry::class)->all() as $entry) {
                $screens[] = $entry->label;
            }
        } catch (Throwable) {
            // Before the menu is built: the fixed names stand for it.
        }

        if ($typePlurals !== null) {
            return [...$screens, ...$typePlurals];
        }

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if ($type->key !== $typeKey && $type->listed) {
                $screens[] = $type->plural;
            }
        }

        foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
            if ($taxonomy->key !== $taxonomyKey) {
                $screens[] = $taxonomy->plural;
            }
        }

        return array_values(array_unique($screens));
    }

    /**
     * Whether two names are the same name, as clash() compares them.
     */
    public static function same(string $a, string $b): bool
    {
        return self::fold($a) === self::fold($b);
    }

    private static function fold(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}

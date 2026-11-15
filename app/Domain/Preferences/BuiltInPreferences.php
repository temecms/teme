<?php

declare(strict_types=1);

namespace App\Domain\Preferences;

use App\Domain\Navigation\Queries\AdminNavigation;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;

/**
 * The preferences 1.5 actually reads, and nothing speculative: each one here
 * has a screen that uses it. Their order is the order the account's
 * preferences screen shows them.
 */
final class BuiltInPreferences
{
    public static function register(PreferenceRegistry $registry): void
    {
        $registry->register(new PreferenceDefinition('editor.spellcheck', 'boolean', true, 'Check spelling', "The browser's spellcheck in the editor's title, excerpt and text."));
        $registry->register(new PreferenceDefinition('editor.smart_quotes', 'boolean', true, 'Smart quotes', 'Turn straight quotes into curly ones as you type. Dashes and ellipses are converted either way.'));
        $registry->register(new PreferenceDefinition('editor.rail_width', 'number', 360, 'Settings panel width', "The editor's side panel, in pixels. Dragging its edge changes it too.", min: 320, max: 480));
        $registry->register(new PreferenceDefinition('editor.focus_mode_default', 'boolean', false, 'Open in focus mode', 'Open the editor with everything but the writing hidden.'));
        // Pinned to the editor's bar (DS 4.39); plugin tools join the allowed list once plugins ship admin code.
        $registry->register(new PreferenceDefinition('editor.pinned_tools', 'list', ['assistant', 'share'], 'Pinned editor tools', allowed: fn (): array => ['assistant', 'share'], maxItems: 3));
        $registry->register(new PreferenceDefinition('lists.per_page', 'select', 50, 'Rows per page', 'How many items the Posts, Pages and other lists show at a time.', options: [25 => '25', 50 => '50', 100 => '100']));
        $registry->register(new PreferenceDefinition('appearance.mode', 'select', 'system', 'Light or dark', 'Light, dark, or whatever your system is set to.', options: ['light' => 'Light', 'dark' => 'Dark', 'system' => 'Match the system']));
        $registry->register(new PreferenceDefinition('dashboard.getting_started_hidden', 'boolean', false, 'Hide Getting started', 'The checklist a new site shows on Home. Turn this off to see it again.'));

        // One choice per kind of notice a person may turn off (I-691):
        // Account › Notifications draws them, worded for each person.
        NoticePreferences::declare($registry);

        // Later (I-200): plugin columns join these options in phase 5, grouped under their plugin.
        // Later (I-416): a type's filterable fields join its options, as fields.{handle}, in phase 2.
        // Later (I-239): a Parent column for a hierarchical type arrives with hierarchy, which phase 1 does not have.
        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            // A type that isn't listed, such as a region, has no list, so no columns.
            if (! $type->listed) {
                continue;
            }

            $columns = self::columnsOf($type->key);

            $registry->register(new PreferenceDefinition(
                "lists.{$type->key}.columns", 'list', $columns, "{$type->plural} columns",
                // Featured image is offered, and never on until someone turns it on (I-3120).
                allowed: fn (): array => ['featured', ...self::columnsOf($type->key)],
                maxItems: count($columns) + 1,
            ));
        }

        // The menu (I-217): any key that exists for anyone, worked out when a
        // value is checked, so an entry registered later is allowed. What the
        // person may open is the menu's own rule, applied when it is read.
        $menuKeys = fn (): array => app(AdminNavigation::class)->allKeys();

        $registry->register(new PreferenceDefinition('nav.hidden', 'list', [], 'Hidden menu entries', allowed: $menuKeys));
        $registry->register(new PreferenceDefinition('nav.order', 'list', [], 'Menu order', allowed: $menuKeys));
        $registry->register(new PreferenceDefinition('nav.pinned', 'list', [], 'Pinned menu entries', allowed: $menuKeys, maxItems: 5));
    }

    /**
     * The columns a type's list may show, as the list page's column ids:
     * Author, one per taxonomy the type uses, in the type's order, Comments,
     * for a type that takes comments, then Date. Title is always shown, so it
     * is no option; a row's state follows its title.
     *
     * @return list<string>
     */
    private static function columnsOf(string $typeKey): array
    {
        // A type deleted since this process built its preferences has no
        // list, so no columns (I-3296).
        if (! app(ContentTypeRegistry::class)->has($typeKey)) {
            return [];
        }

        return [
            'author',
            ...array_map(fn (Taxonomy $taxonomy): string => "taxonomy-{$taxonomy->key}", app(TaxonomyRegistry::class)->forType($typeKey)),
            ...(app(ContentTypeRegistry::class)->get($typeKey)->comments === null ? [] : ['comments']),
            'date',
        ];
    }
}

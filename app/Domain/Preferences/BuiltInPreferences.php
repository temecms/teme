<?php

/**
 * The preferences 1.5 actually reads, and nothing speculative: each one here
 * has a screen that uses it. Their order is the order the account's
 * preferences screen shows them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Preferences;

use App\Domain\Contents\Queries\ListFields;
use App\Domain\Dashboard\DashboardPanel;
use App\Domain\Dashboard\DashboardRegistry;
use App\Domain\Navigation\Queries\AdminNavigation;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Plugins\Columns\PluginColumns;
use App\Domain\Plugins\Editor\EditorPanels;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\Auth;

final class BuiltInPreferences
{
    public static function register(PreferenceRegistry $registry): void
    {
        $registry->register(new PreferenceDefinition('editor.spellcheck', 'boolean', true, 'Check spelling', "The browser's spellcheck in the editor's title, excerpt and text."));
        $registry->register(new PreferenceDefinition('editor.smart_quotes', 'boolean', true, 'Smart quotes', 'Turn straight quotes into curly ones as you type. Dashes and ellipses are converted either way.'));
        $registry->register(new PreferenceDefinition('editor.rail_width', 'number', 360, 'Settings panel width', "The editor's side panel, in pixels. Dragging its edge changes it too.", min: 320, max: 480));
        $registry->register(new PreferenceDefinition('editor.focus_mode_default', 'boolean', false, 'Open in focus mode', 'Open the editor with everything but the writing hidden.'));
        // Pinned to the editor's bar (DS 4.39), with the plugins' panels the signed-in person may see on some item.
        $registry->register(new PreferenceDefinition('editor.pinned_tools', 'list', ['assistant', 'share'], 'Pinned editor tools', allowed: fn (): array => ['assistant', 'share', ...app(EditorPanels::class)->toolIdsFor(Auth::id() === null ? null : (string) Auth::id())], maxItems: 3));
        $registry->register(new PreferenceDefinition('lists.per_page', 'select', 10, 'Rows per page', 'How many posts, pages, media items or comments a list shows before it starts a new page.', options: [10 => '10', 25 => '25', 50 => '50', 100 => '100']));
        $registry->register(new PreferenceDefinition('appearance.mode', 'select', 'system', 'Light or dark', 'Light, dark, or whatever your system is set to.', options: ['light' => 'Light', 'dark' => 'Dark', 'system' => 'Match the system']));
        $registry->register(new PreferenceDefinition('dashboard.getting_started_hidden', 'boolean', false, 'Hide Getting started', 'The checklist a new site shows on Home. Turn this off to see it again.'));

        // One choice per kind of notice a person may turn off (I-691):
        // Account › Notifications draws them, worded for each person.
        NoticePreferences::declare($registry);

        // Later (I-200): row actions, bulk actions and filters from plugins wait.
        // Later (I-416): a type's filterable fields join its options, as fields.{handle}, in phase 2.
        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            // A type that isn't listed, such as a region, has no list, so no columns.
            if (! $type->listed) {
                continue;
            }

            $columns = self::columnsOf($type->key);
            $typeKey = $type->key;

            $registry->register(new PreferenceDefinition(
                "lists.{$type->key}.columns", 'list', self::shownAtFirst($type->key), "{$type->plural} columns",
                // Thumbnails show until someone turns them off, as the approved design draws the
                // lists; Languages is offered (docs/proposals/multilingual-screens.md, 4). A type's
                // own fields are `fields` until the person chooses among them, each as field-{key}.
                // Plugin columns join the same way. maxItems is null: the allowed set bounds the list,
                // and a fixed number would have to know how many plugins run.
                allowed: fn (): array => [
                    'featured', 'languages', 'fields', ...self::columnsOf($typeKey),
                    ...array_map(fn (array $field): string => "field-{$field['key']}", ListFields::columns($typeKey)),
                    ...app(PluginColumns::class)->keysFor($typeKey),
                ],
                maxItems: null,
            ));
        }

        // The menu (I-217): any key that exists for anyone, worked out when a
        // value is checked, so an entry registered later is allowed. What the
        // person may open is the menu's own rule, applied when it is read.
        $menuKeys = fn (): array => app(AdminNavigation::class)->allKeys();

        $registry->register(new PreferenceDefinition('nav.hidden', 'list', [], 'Hidden menu entries', allowed: $menuKeys));
        $registry->register(new PreferenceDefinition('nav.order', 'list', [], 'Menu order', allowed: $menuKeys));
        $registry->register(new PreferenceDefinition('nav.pinned', 'list', [], 'Pinned menu entries', allowed: $menuKeys, maxItems: 5));

        // Home (I-210): each person's order, hidden panels and sizes, over the
        // registry's declared order and sizes, which are the site default.
        $panelKeys = fn (): array => array_map(fn (DashboardPanel $panel): string => $panel->key, app(DashboardRegistry::class)->all());

        $registry->register(new PreferenceDefinition('dashboard.order', 'list', [], 'Home panel order', allowed: $panelKeys));
        $registry->register(new PreferenceDefinition('dashboard.hidden', 'list', [], 'Hidden Home panels', allowed: $panelKeys));
        $registry->register(new PreferenceDefinition('dashboard.sizes', 'list', [], 'Home panel sizes', allowed: fn (): array => array_merge(...array_map(
            fn (string $key): array => array_map(fn (string $size): string => "{$key}:{$size}", DashboardPanel::SIZES),
            $panelKeys(),
        ))));
    }

    /**
     * The columns a type's list may show, as the list page's column ids:
     * Parent, for a type that nests, Author, one per taxonomy the type uses,
     * in the type's order, Template, for a type that chooses one, Comments,
     * for a type that takes comments, then Date. Title is always shown, so it
     * is no option; nor is Status, which always comes before the Date.
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

        $type = app(ContentTypeRegistry::class)->get($typeKey);

        return [
            ...($type->hierarchical ? ['parent'] : []),
            'author',
            ...array_map(fn (Taxonomy $taxonomy): string => "taxonomy-{$taxonomy->key}", app(TaxonomyRegistry::class)->forType($typeKey)),
            ...($type->supports('template') ? ['template'] : []),
            ...($type->comments === null ? [] : ['comments']),
            'date',
        ];
    }

    /**
     * What a list shows before the person chooses, so every column fits
     * beside the title at 1440 wide: the thumbnails, every column but a flat
     * taxonomy beside a nesting one, Comments, and Template on a type that
     * doesn't nest, and the type's own
     * fields (read when the list is drawn, never at boot).
     *
     * @return list<string>
     */
    private static function shownAtFirst(string $typeKey): array
    {
        $taxonomies = app(ContentTypeRegistry::class)->has($typeKey) ? app(TaxonomyRegistry::class)->forType($typeKey) : [];
        $nests = array_filter($taxonomies, fn (Taxonomy $taxonomy): bool => $taxonomy->hierarchical) !== [];
        $nesting = app(ContentTypeRegistry::class)->has($typeKey) && app(ContentTypeRegistry::class)->get($typeKey)->hierarchical;
        $offeredOnly = [
            'comments',
            // Pages show their template; Posts offer it.
            ...($nesting ? [] : ['template']),
            ...array_map(
                fn (Taxonomy $taxonomy): string => "taxonomy-{$taxonomy->key}",
                array_filter($taxonomies, fn (Taxonomy $taxonomy): bool => $nests && ! $taxonomy->hierarchical),
            ),
        ];
        $shown = array_values(array_filter(self::columnsOf($typeKey), fn (string $id): bool => ! in_array($id, $offeredOnly, true)));
        $dateAt = array_search('date', $shown, true);

        return $dateAt === false
            ? ['featured', ...$shown]
            : ['featured', ...array_slice($shown, 0, $dateAt), 'fields', ...array_slice($shown, $dateAt)];
    }
}

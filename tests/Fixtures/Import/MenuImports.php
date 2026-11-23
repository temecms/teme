<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Contents\Models\Content;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\Menus\MenusPhase;
use App\Domain\Import\Phases\Menus\UnplacedMenusStep;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Models\NavigationItem;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The menus import's test fixtures (I-343): the companion's menus answer
 * built item by item, the map rows the posts, pages and terms phases leave,
 * a run of the menus phase alone, and what a menu holds once stored.
 */
final class MenuImports
{
    /**
     * A menu's items as stored, each with the items under it, in order:
     * what SaveNavigationItems takes, so a test can save it back.
     *
     * @return list<array{kind: string, target_id: string|null, url: string|null, label: string|null, opens_new_tab: bool, children: list<mixed>}>
     */
    public static function stored(Navigation $navigation, ?string $parent = null): array
    {
        return NavigationItem::query()
            ->where('navigation_id', $navigation->id)
            ->where('parent_id', $parent)
            ->orderBy('position')
            ->get()
            ->map(fn (NavigationItem $item): array => [
                'kind' => $item->kind,
                'target_id' => $item->target_id,
                'url' => $item->url,
                'label' => $item->label,
                'opens_new_tab' => $item->opens_new_tab,
                'children' => self::stored($navigation, $item->id),
            ])
            ->values()
            ->all();
    }

    /**
     * One classic menu item as the companion sends it: a custom link to the
     * old site's home labelled Home, at the top, in its ID's order, but for
     * $fields.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array<string, mixed>
     */
    public static function item(int $id, array $fields = []): array
    {
        return [
            'id' => $id, 'parent' => 0, 'order' => $id, 'type' => 'custom', 'object' => 'custom', 'object_id' => $id,
            'url' => 'https://old.example/', 'label' => 'Home', 'label_is_custom' => true, 'target' => '',
            'attr_title' => '', 'classes' => [], 'xfn' => '', 'invalid' => false,
            ...$fields,
        ];
    }

    /**
     * One classic menu as the companion sends it.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @return array<string, mixed>
     */
    public static function menu(int $id, array $items, string $name = 'Main'): array
    {
        return ['id' => $id, 'name' => $name, 'slug' => Str::slug($name) ?: "menu-{$id}", 'items' => $items];
    }

    /**
     * One navigation post as the companion sends it, its blocks as parse_blocks() gives them.
     *
     * @param  list<array<string, mixed>>  $blocks
     *
     * @return array<string, mixed>
     */
    public static function navigation(int $id, array $blocks, string $title = 'Header navigation', string $status = 'publish'): array
    {
        return ['id' => $id, 'title' => $title, 'status' => $status, 'blocks' => $blocks];
    }

    /**
     * One block as parse_blocks() gives it; a null name is the whitespace between blocks.
     *
     * @param  array<string, mixed>  $attrs
     * @param  list<array<string, mixed>>  $inner
     *
     * @return array<string, mixed>
     */
    public static function block(?string $name, array $attrs = [], array $inner = []): array
    {
        return [
            'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $inner,
            'innerHTML' => $name === null ? "\n\n" : '', 'innerContent' => $name === null ? ["\n\n"] : [],
        ];
    }

    /**
     * The companion's whole answer to menus: these classic menus and
     * navigation posts, and WordPress's locations, each showing the menu
     * with that term ID.
     *
     * @param  list<array<string, mixed>>  $menus
     * @param  list<array<string, mixed>>  $navigation
     * @param  array<string, int>  $assigned
     *
     * @return array<string, mixed>
     */
    public static function answer(array $menus, array $navigation = [], array $assigned = []): array
    {
        return [
            'api' => 1,
            'menus' => $menus,
            'registered_locations' => array_map(fn (int $menu): string => 'A menu location', $assigned),
            'assigned_locations' => $assigned,
            'navigation' => $navigation,
        ];
    }

    /**
     * WordPress post or page $wordpressId as the posts and pages phase
     * leaves it, by an earlier finished run: imported as $item with these
     * facts (menu_order, parent), or, with no item, failed or skipped as
     * $outcome says. The map row's ID.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function post(ImportSource $source, int $wordpressId, ?Content $item, array $facts = [], string $outcome = 'created'): string
    {
        return ImportRecords::entry($source->id, ImportRecords::run($source->id), 'post', (string) $wordpressId, $outcome, [
            'teme_type' => $item === null ? null : 'content',
            'teme_id' => $item?->id,
            'facts' => json_encode($facts === [] ? (object) [] : $facts, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * A WordPress term as the terms phase leaves it: keyed by its
     * term_taxonomy_id, its term ID and WordPress taxonomy in its facts.
     * The map row's ID.
     */
    public static function term(ImportSource $source, int $termTaxonomyId, Term $term, int $termId, string $taxonomy): string
    {
        return ImportRecords::entry($source->id, ImportRecords::run($source->id), 'term', (string) $termTaxonomyId, 'created', [
            'teme_type' => 'term',
            'teme_id' => $term->id,
            'facts' => json_encode(['term_id' => $termId, 'taxonomy' => $taxonomy, 'parent' => 0, 'path' => null], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The menus phase alone, run to its end over this answer to menus (the
     * fixture's when null) as $actor (the operator when null), with these
     * choices.
     *
     * @param  array<string, mixed>|null  $answer
     * @param  array<string, mixed>  $choices
     */
    public static function run(FakeCompanion $companion, ImportSource $source, ?array $answer, array $choices = [], ?User $actor = null): ImportRun
    {
        if ($answer !== null) {
            $companion->items('menus', $answer);
        }

        importPhaseAlone(app(MenusPhase::class), app(UnplacedMenusStep::class));

        return importRun($source, $actor, ImportRunKind::Full, $choices);
    }

    /**
     * Menu $id's map row: a classic menu's term ID, or navigation:{post ID}.
     */
    public static function entry(ImportSource $source, string $id): ImportMapEntry
    {
        return ImportMapEntry::query()->where('source_id', $source->id)->where('object_type', 'menu')->where('object_id', $id)->firstOrFail();
    }

    /**
     * The Teme menu WordPress menu $id became.
     */
    public static function navigationOf(ImportSource $source, string $id): Navigation
    {
        return Navigation::query()->findOrFail(self::entry($source, $id)->teme_id);
    }

    /**
     * The codes of menu $id's messages, in order.
     *
     * @return list<string>
     */
    public static function codes(ImportSource $source, string $id): array
    {
        return array_column(self::messages($source, $id), 0);
    }

    /**
     * Menu $id's messages as [code, words], by code, then words.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function messages(ImportSource $source, string $id): array
    {
        return ImportMessageRecord::query()->where('map_id', self::entry($source, $id)->id)->orderBy('code')->orderBy('message')->get()
            ->map(fn (ImportMessageRecord $message): array => [$message->code, $message->message])->values()->all();
    }

    /**
     * The run's own messages whose code starts with $prefix, as [code, words], by code, then words.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function runMessages(ImportRun $run, string $prefix): array
    {
        return ImportMessageRecord::query()->where('run_id', $run->id)->whereNull('map_id')->where('code', 'like', $prefix.'%')->orderBy('code')->orderBy('message')->get()
            ->map(fn (ImportMessageRecord $message): array => [$message->code, $message->message])->values()->all();
    }
}

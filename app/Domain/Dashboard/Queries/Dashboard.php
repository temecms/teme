<?php

/**
 * The admin's Home screen for one person: their panels, each one bounded
 * query on its own index, and the site's figures. A panel is asked only for
 * the types on which the viewer holds all its actions, read once per type
 * through ContentAbilities (B-178), so the dashboard restates no policy rule
 * of its own; a panel no type grants is not sent. Empty rows panels stay out
 * of `panels` outside Customize (I-161); the person's arrangement is their
 * preferences (I-210). It reads only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Dashboard\Queries;

use App\Domain\Contents\Queries\ContentAbilities;
use App\Domain\Dashboard\DashboardPanel;
use App\Domain\Dashboard\DashboardPanelQuery;
use App\Domain\Dashboard\DashboardRegistry;
use App\Domain\Dashboard\MainType;
use App\Domain\Plugins\Area\HomePanelDeclaration;
use App\Domain\Plugins\Area\PluginHomePanels;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginAreaScreen;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Seo\Queries\SearchEnginesNotice;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Throwable;

final readonly class Dashboard
{
    /** At most this many rows in any panel. */
    private const int ROWS = 5;

    public function __construct(
        private DashboardRegistry $registry,
        private ContentTypeRegistry $types,
        private SiteFigures $figures,
        private GettingStarted $gettingStarted,
        private SearchEnginesNotice $searchEngines,
        private PluginRegistry $plugins,
        private PluginHomePanels $homePanels,
        private PluginAreaScreen $areaScreen,
    ) {}

    /**
     * The viewer's panels, in their order, without hidden ones; every panel
     * they may see in the catalogue; their layout; the site's figures; and
     * the main type, with whether they may create one or upload media.
     *
     * @return array{
     *   panels: list<array{key: string, label: string, rows: list<array{id: string, title: string, subtitle: string, url: string, at: string}>, seeAll: string|null, failed: bool, blocks: list<array<string, mixed>>}>,
     *   catalogue: list<array{key: string, label: string, size: 'small'|'medium'|'large'|'full', kind: 'rows'|'quick-draft'|'site-status'|'plugin', owner: string, ownerName: string|null}>,
     *   layout: array{order: list<string>, hidden: list<string>, sizes: array<string, 'small'|'medium'|'large'|'full'>},
     *   figures: list<array{key: string, label: string, dot: string, value: int, url: string|null}>,
     *   mainType: array{singular: string, segment: string, canCreate: bool},
     *   create: list<array{singular: string, segment: string}>,
     *   gettingStarted: array{steps: list<array{key: string, label: string, done: bool, url: string}>}|null,
     *   canQuickDraft: bool,
     *   canUpload: bool,
     *   searchEngines: array{message: string, detail: string, actionLabel: string, actionUrl: string}|null
     * }
     */
    public function forUser(User $viewer): array
    {
        $held = $this->heldActions($viewer);
        $main = MainType::get();
        $canCreate = in_array('create', $held[$main->key] ?? [], true);
        $catalogue = $this->catalogue($viewer, $held, $canCreate);
        $layout = $this->layout($viewer, $catalogue);
        $byKey = [];

        foreach ($this->registry->all() as $panel) {
            $byKey[$panel->key] = $panel;
        }

        $panels = [];

        foreach ($layout['order'] as $key) {
            if (in_array($key, $layout['hidden'], true)) {
                continue;
            }

            $entry = collect($catalogue)->firstWhere('key', $key);

            if ($entry !== null && $entry['kind'] === 'plugin') {
                $declared = $this->homePanels->find($key);

                if ($declared !== null) {
                    $panels[] = $this->pluginPanel($viewer, $declared);
                }

                continue;
            }

            if ($entry === null || $entry['kind'] !== 'rows') {
                continue;
            }

            /** @var DashboardPanel $panel */
            $panel = $byKey[$key];
            $types = array_values(array_filter(
                $this->listed(),
                fn (ContentType $type): bool => array_diff($panel->actions, $held[$type->key]) === [],
            ));

            try {
                /** @var DashboardPanelQuery $query */
                $query = app($panel->query);
                $rows = $query->rows($viewer, $types, self::ROWS);
            } catch (Throwable $e) {
                report($e);
                $panels[] = [
                    'key' => $panel->key,
                    'label' => $panel->label,
                    'rows' => [],
                    'seeAll' => $this->seeAll($panel, $main, $viewer),
                    'failed' => true,
                    'blocks' => [],
                ];

                continue;
            }

            if ($rows !== []) {
                $panels[] = [
                    'key' => $panel->key,
                    'label' => $panel->label,
                    'rows' => $rows,
                    'seeAll' => $this->seeAll($panel, $main, $viewer),
                    'failed' => false,
                    'blocks' => [],
                ];
            }
        }

        return [
            'panels' => $panels,
            'catalogue' => $catalogue,
            'layout' => $layout,
            'figures' => $this->figures->forUser($viewer),
            'mainType' => [
                'singular' => $main->singular,
                'segment' => $main->segment,
                'canCreate' => $canCreate,
            ],
            'create' => $this->createActions($held),
            'gettingStarted' => $this->gettingStarted->forUser($viewer),
            'canQuickDraft' => $canCreate,
            'canUpload' => $viewer->can('media.upload'),
            'searchEngines' => $this->searchEngines->forUser($viewer),
        ];
    }

    /**
     * The create actions the viewer may use from Home's header, for the
     * first two types the admin lists, which are core's own, registered first.
     *
     * @param  array<string, list<string>>  $held
     *
     * @return list<array{singular: string, segment: string}>
     */
    private function createActions(array $held): array
    {
        $create = [];

        $first = array_slice(array_values(array_filter($this->listed(), fn (ContentType $type): bool => ! $type->stored)), 0, 2);

        foreach ($first as $type) {
            if (! in_array('create', $held[$type->key] ?? [], true)) {
                continue;
            }

            $create[] = [
                'singular' => $type->singular,
                'segment' => $type->segment,
            ];
        }

        return $create;
    }

    /**
     * Every panel the viewer may see, empty or not, in registry order.
     *
     * @param  array<string, list<string>>  $held
     *
     * @return list<array{key: string, label: string, size: 'small'|'medium'|'large'|'full', kind: 'rows'|'quick-draft'|'site-status'|'plugin', owner: string, ownerName: string|null}>
     */
    private function catalogue(User $viewer, array $held, bool $canQuickDraft): array
    {
        $catalogue = [];

        foreach ($this->registry->all() as $panel) {
            if ($panel->kind === 'quick-draft') {
                if (! $canQuickDraft) {
                    continue;
                }
            } elseif ($panel->kind === 'site-status') {
                // Everyone who opens Home may see the site status summary.
            } elseif ($panel->kind === 'plugin') {
                // A plugin's panel is for the people who may open its area.
                $declared = $this->homePanels->find($panel->key);

                if ($declared === null || ! $viewer->can($declared->plugin->ability())) {
                    continue;
                }
            } else {
                $types = array_values(array_filter(
                    $this->listed(),
                    fn (ContentType $type): bool => array_diff($panel->actions, $held[$type->key]) === [],
                ));

                if ($types === []) {
                    continue;
                }
            }

            $catalogue[] = [
                'key' => $panel->key,
                'label' => $panel->label,
                'size' => $panel->size,
                'kind' => $panel->kind,
                'owner' => $panel->owner,
                'ownerName' => $this->ownerName($panel->owner),
            ];
        }

        return $catalogue;
    }

    /**
     * The person's arrangement, cleaned against the catalogue.
     *
     * @param  list<array{key: string, label: string, size: 'small'|'medium'|'large'|'full', kind: 'rows'|'quick-draft'|'site-status', owner: string, ownerName: string|null}>  $catalogue
     *
     * @return array{order: list<string>, hidden: list<string>, sizes: array<string, 'small'|'medium'|'large'|'full'>}
     */
    private function layout(User $viewer, array $catalogue): array
    {
        $keys = array_column($catalogue, 'key');
        $declared = [];

        foreach ($catalogue as $entry) {
            $declared[$entry['key']] = $entry['size'];
        }

        $preferences = PreferencesFor::user($viewer);
        /** @var list<string> $storedOrder */
        $storedOrder = $preferences['dashboard.order'];
        /** @var list<string> $storedHidden */
        $storedHidden = $preferences['dashboard.hidden'];
        /** @var list<string> $storedSizes */
        $storedSizes = $preferences['dashboard.sizes'];

        $order = array_values(array_filter($storedOrder, fn (string $key): bool => in_array($key, $keys, true)));

        foreach ($keys as $key) {
            if (! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        $hidden = array_values(array_filter($storedHidden, fn (string $key): bool => in_array($key, $keys, true)));

        $sizes = $declared;
        $sized = [];

        foreach ($storedSizes as $entry) {
            if (! str_contains($entry, ':')) {
                continue;
            }

            [$key, $size] = explode(':', $entry, 2);

            if (isset($sized[$key]) || ! array_key_exists($key, $sizes) || ! in_array($size, DashboardPanel::SIZES, true)) {
                continue;
            }

            $sized[$key] = true;
            $sizes[$key] = $size;
        }

        return [
            'order' => $order,
            'hidden' => $hidden,
            'sizes' => $sizes,
        ];
    }

    /**
     * A plugin's panel: each of its blocks read for the viewer under the
     * area's budgets. A block that couldn't be read says so, and the panel is
     * failed when every block that reads data couldn't be shown.
     *
     * @return array{key: string, label: string, rows: list<never>, seeAll: null, failed: bool, blocks: list<array<string, mixed>>}
     */
    private function pluginPanel(User $viewer, HomePanelDeclaration $declared): array
    {
        $blocks = [];

        foreach ($declared->blocks as $blockId) {
            $block = $declared->plugin->area->block($declared->page, $blockId);

            if ($block === null) {
                continue;
            }

            try {
                $blocks[] = $this->areaScreen->homeBlock($declared->plugin, $declared->page, $block, (string) $viewer->getAuthIdentifier());
            } catch (Throwable $e) {
                report($e);
                $blocks[] = ['id' => $blockId, 'type' => (string) ($block['type'] ?? ''), 'data' => null, 'failed' => true];
            }
        }

        $reading = array_filter($blocks, fn (array $block): bool => in_array($block['type'] ?? null, ['figures', 'chart', 'table'], true));
        $failed = $reading !== [] && array_all($reading, fn (array $block): bool => self::blockFailed($block));

        return [
            'key' => $declared->key,
            'label' => $declared->title,
            'rows' => [],
            'seeAll' => null,
            'failed' => $failed,
            'blocks' => $blocks,
        ];
    }

    /**
     * Whether a block's data couldn't be shown: the block failed to read, or
     * its data carries the line saying why.
     *
     * @param  array<string, mixed>  $block
     */
    private static function blockFailed(array $block): bool
    {
        $data = $block['data'] ?? null;

        return ($block['failed'] ?? false) === true || (is_array($data) && is_string($data['failed'] ?? null));
    }

    /**
     * The plugin's label for a non-core owner, as plugin areas name a plugin
     * on its pages; null for core.
     */
    private function ownerName(string $owner): ?string
    {
        if ($owner === 'core') {
            return null;
        }

        $plugin = $this->plugins->find($owner);

        return $plugin?->manifest?->label() ?? $owner;
    }

    /**
     * The types the panels show, as the lists do: listed ones only. A type
     * that isn't listed, such as a region, has its own screen.
     *
     * @return list<ContentType>
     */
    private function listed(): array
    {
        return array_values(array_filter($this->types->all(), fn (ContentType $type): bool => $type->listed));
    }

    /**
     * The actions the viewer holds on each type: ContentType::ACTIONS keys,
     * never ability names.
     *
     * @return array<string, list<string>>
     */
    private function heldActions(User $viewer): array
    {
        $held = [];

        foreach ($this->listed() as $type) {
            $abilities = ContentAbilities::forUser($viewer, $type->key);

            $held[$type->key] = array_keys(array_filter([
                'edit.any' => $abilities->editAny,
                'edit.own' => $abilities->editOwn,
                'publish' => $abilities->publish,
                'create' => $viewer->can($type->ability('create')),
            ]));
        }

        return $held;
    }

    /**
     * The main type's list that answers the panel in full, built each time
     * from what the panel registered.
     */
    private function seeAll(DashboardPanel $panel, ContentType $main, User $viewer): ?string
    {
        if ($panel->seeAllTab === null) {
            return null;
        }

        return route('content.index', [
            'type' => $main->segment,
            'tab' => $panel->seeAllTab,
            ...$panel->seeAllQuery,
            ...($panel->seeAllMine ? ['author' => $viewer->getAuthIdentifier()] : []),
        ]);
    }
}

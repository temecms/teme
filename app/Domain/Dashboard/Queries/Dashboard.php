<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Queries;

use App\Domain\Contents\Queries\ContentAbilities;
use App\Domain\Dashboard\DashboardPanel;
use App\Domain\Dashboard\DashboardPanelQuery;
use App\Domain\Dashboard\DashboardRegistry;
use App\Domain\Dashboard\MainType;
use App\Domain\Seo\Queries\SearchEnginesNotice;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;

/**
 * The admin's Home screen for one person: their panels, each one bounded
 * query on its own index, and the site's figures. A panel is asked only for
 * the types on which the viewer holds all its actions, read once per type
 * through ContentAbilities (B-178), so the dashboard restates no policy rule
 * of its own; a panel no type grants, or with no rows, is not sent. The
 * number of queries is the same however much the site holds. It reads only.
 */
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
    ) {}

    /**
     * The viewer's panels, in order, with their rows and See all link; the
     * site's figures; and the main type, with whether the viewer may create
     * one; Getting started, for someone who may manage the site; and whether
     * they may save a quick draft; and, for whoever may change Search and
     * social, the warning while search engines are asked to stay away.
     *
     * @return array{panels: list<array{key: string, label: string, rows: list<array{id: string, title: string, subtitle: string, url: string, at: string}>, seeAll: string|null}>, figures: list<array{key: string, label: string, group: string|null, value: int, url: string|null}>, mainType: array{singular: string, segment: string, canCreate: bool}, gettingStarted: array{steps: list<array{key: string, label: string, done: bool, url: string}>}|null, canQuickDraft: bool, searchEngines: array{message: string, detail: string, actionLabel: string, actionUrl: string}|null}
     */
    public function forUser(User $viewer): array
    {
        $held = $this->heldActions($viewer);
        $main = MainType::get();
        $panels = [];

        foreach ($this->registry->all() as $panel) {
            $types = array_values(array_filter(
                $this->listed(),
                fn (ContentType $type): bool => array_diff($panel->actions, $held[$type->key]) === [],
            ));

            if ($types === []) {
                continue;
            }

            /** @var DashboardPanelQuery $query */
            $query = app($panel->query);
            $rows = $query->rows($viewer, $types, self::ROWS);

            if ($rows !== []) {
                $panels[] = [
                    'key' => $panel->key,
                    'label' => $panel->label,
                    'rows' => $rows,
                    'seeAll' => $this->seeAll($panel, $main, $viewer),
                ];
            }
        }

        $canCreate = in_array('create', $held[$main->key] ?? [], true);

        return [
            'panels' => $panels,
            'figures' => $this->figures->forUser($viewer),
            'mainType' => [
                'singular' => $main->singular,
                'segment' => $main->segment,
                'canCreate' => $canCreate,
            ],
            'gettingStarted' => $this->gettingStarted->forUser($viewer),
            'canQuickDraft' => $canCreate,
            'searchEngines' => $this->searchEngines->forUser($viewer),
        ];
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

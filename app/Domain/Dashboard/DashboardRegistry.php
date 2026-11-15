<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use InvalidArgumentException;

/**
 * Every panel the Home screen can show, as the menu is a registry of
 * entries: core registers its panels, and nothing names them anywhere else.
 */
final class DashboardRegistry
{
    /** @var array<string, DashboardPanel> */
    private array $panels = [];

    public function register(DashboardPanel $panel): void
    {
        // Later (I-200): plugins register panels in phase 5, shown in their own labelled
        // section below core's. Later (I-210): each person arranges panels, with a site default.
        if (isset($this->panels[$panel->key])) {
            throw new InvalidArgumentException("Dashboard panel [{$panel->key}] is already registered.");
        }

        if (! in_array($panel->size, DashboardPanel::SIZES, true)) {
            throw new InvalidArgumentException("Dashboard panel [{$panel->key}] has an unknown size [{$panel->size}].");
        }

        if (! in_array($panel->kind, DashboardPanel::KINDS, true)) {
            throw new InvalidArgumentException("Dashboard panel [{$panel->key}] has an unknown kind [{$panel->kind}].");
        }

        if ($panel->kind === 'rows') {
            if ($panel->query === null || ! is_subclass_of($panel->query, DashboardPanelQuery::class)) {
                throw new InvalidArgumentException("Dashboard panel [{$panel->key}]'s query must implement DashboardPanelQuery.");
            }
        }

        $this->panels[$panel->key] = $panel;
    }

    /**
     * Every panel, by order and then key.
     *
     * @return list<DashboardPanel>
     */
    public function all(): array
    {
        $panels = array_values($this->panels);
        usort($panels, fn (DashboardPanel $a, DashboardPanel $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        return $panels;
    }
}

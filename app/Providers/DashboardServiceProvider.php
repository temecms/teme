<?php

/**
 * Registers the Home screen's panel registry, and core's panels: the
 * person's own work first (study P §3, I-161). A panel names the actions it
 * needs as ContentType::ACTIONS keys, never an ability.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Dashboard\DashboardPanel;
use App\Domain\Dashboard\DashboardRegistry;
use App\Domain\Dashboard\Panels\MyDrafts;
use App\Domain\Dashboard\Panels\MyItemsInReview;
use App\Domain\Dashboard\Panels\MyRecentEdits;
use App\Domain\Dashboard\Panels\ReturnedToMe;
use App\Domain\Dashboard\Panels\Scheduled;
use App\Domain\Dashboard\Panels\WaitingForMyReview;
use App\Domain\Plugins\Area\PluginHomePanels;
use Illuminate\Support\ServiceProvider;

final class DashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: the plugins' Home panels are read once per request or job.
        $this->app->scoped(PluginHomePanels::class);

        $this->app->singleton(DashboardRegistry::class, function (): DashboardRegistry {
            $registry = new DashboardRegistry;

            // Both review panels open the list with a review filter, not the In
            // review tab, which leaves out a live article whose update waits.
            // Default sizes (I-3401): review and drafts Medium; Scheduled, recent edits and Quick draft Small.
            $registry->register(new DashboardPanel('waiting_for_my_review', 'Waiting for your review', 100, WaitingForMyReview::class, ['publish', 'edit.any'], 'all', seeAllQuery: ['review' => 'pending'], size: 'medium'));
            $registry->register(new DashboardPanel('returned_to_me', 'Returned to you', 150, ReturnedToMe::class, [], 'mine', size: 'medium'));
            $registry->register(new DashboardPanel('my_items_in_review', 'Your items in review', 200, MyItemsInReview::class, [], 'all', seeAllQuery: ['review' => 'submitted'], size: 'medium'));
            $registry->register(new DashboardPanel('my_drafts', 'Your drafts', 300, MyDrafts::class, ['create'], 'drafts', seeAllMine: true, size: 'medium'));
            $registry->register(new DashboardPanel('scheduled', 'Scheduled', 400, Scheduled::class, ['create'], 'scheduled', size: 'small'));
            $registry->register(new DashboardPanel('my_recent_edits', 'You edited recently', 500, MyRecentEdits::class, ['create'], size: 'small'));
            $registry->register(new DashboardPanel('quick_draft', 'Quick draft', 600, null, ['create'], size: 'small', kind: 'quick-draft'));
            // Same checks as the top-bar site status: summary lines on Home (small packs with other smalls).
            $registry->register(new DashboardPanel('site_status', 'Site status', 50, null, [], size: 'small', kind: 'site-status'));

            // Plugins' panels come after core's, each owned by its plugin (docs/guides/developers/plugins/home-panels.md).
            foreach (app(PluginHomePanels::class)->declared() as $declared) {
                $registry->register($declared->panel());
            }

            return $registry;
        });
    }
}

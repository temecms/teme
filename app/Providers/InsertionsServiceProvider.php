<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Site\Document\Placements;
use App\Domain\Site\Insertions\CoreInsertionsChanged;
use App\Domain\Site\Insertions\InsertionOrigins;
use App\Domain\Site\Insertions\InsertionPoints;
use App\Domain\Site\Insertions\PageInsertions;
use App\Domain\Site\Insertions\PluginPlacements;
use App\Domain\Site\Links\ArticleLinkAttributes;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Insertions\InsertionsChanged;

/**
 * Registers article insertion points (I-064): the registry that collects
 * contributors, and the door plugins call when their rules change; and the
 * page insertions, drawn in core's placements.
 */
final class InsertionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(InsertionPoints::class);
        $this->app->scoped(InsertionOrigins::class);
        $this->app->scoped(CoreInsertionsChanged::class);
        $this->app->bind(InsertionsChanged::class, CoreInsertionsChanged::class);
        $this->app->scoped(PageInsertions::class);
        // One page's budget for the plugins asked about its links (docs/plans/plugin-sockets-3.md).
        $this->app->scoped(ArticleLinkAttributes::class);
        $this->app->tag([PluginPlacements::class], Placements::CONTRIBUTORS);
    }
}

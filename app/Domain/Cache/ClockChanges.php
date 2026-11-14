<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Site\SiteDates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The next moment a public page can change with no Operation to purge it
 * (docs/studies/page-cache.md §3.9, I-266): a published item dated ahead
 * comes into view, as lists, its address and menus show it only from its
 * date, or a new year starts on the site's clock, which every footer
 * shows. No stored page lives past it. A scheduled publication needs no
 * cap: RunScheduledPublication publishes through PublishRevision, which
 * purges.
 */
final readonly class ClockChanges
{
    /**
     * contents_published_order's own condition, word for word and bound to
     * nothing, so PostgreSQL can prove the index applies however it plans
     * the statement, as LatestViews writes its pinned index's (1.6h).
     */
    private const string LIVE = "status = 'published' AND deleted_at IS NULL";

    /**
     * The first moment after $now at which a page drawn now may show
     * something else, as a Unix time: the next item dated ahead to come into
     * view, or the new year on the site's clock, whichever is sooner.
     */
    public function next(int $now): int
    {
        $newYear = SiteDates::inSite(CarbonImmutable::createFromTimestamp($now))->startOfYear()->addYear()->getTimestamp();
        $datedAhead = DB::table('contents')
            ->whereRaw(self::LIVE)
            ->where('published_at', '>', gmdate('Y-m-d\TH:i:s\Z', $now))
            ->min('published_at');

        return $datedAhead === null ? $newYear : min($newYear, CarbonImmutable::parse((string) $datedAhead)->getTimestamp());
    }
}

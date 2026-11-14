<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Contents\Jobs\ReindexSearchJob;
use App\Domain\Support\Security\JobActor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The rebuilds a release may ask for (I-3113): work on existing content too
 * big for one SQL statement in a migration, which teme:deploy queues as the
 * operator once the migrations have run. A migration asks in its own
 * transaction, so the request stands exactly when the migration does.
 */
final readonly class ReleaseRebuilds
{
    /**
     * Every rebuild this code knows, by the name a migration asks for.
     * Never remove or rename a key once a migration asks for it: a site
     * that hasn't run that migration yet still will.
     *
     * @var list<string>
     */
    public const array KEYS = ['addresses', 'search'];

    /**
     * Records that this release needs $rebuild, called in a migration's
     * up(). Asked again after it was queued, it waits to be queued again.
     * search rebuilds only items whose search_config is NULL or another
     * language's, so a migration asking for it first sets search_config to
     * NULL on the items it wants rebuilt; addresses moves every address.
     *
     * @throws InvalidArgumentException for one this code doesn't know, which fails the migration
     */
    public static function ask(string $rebuild): void
    {
        if (! in_array($rebuild, self::KEYS, true)) {
            throw new InvalidArgumentException("No rebuild is called {$rebuild}. A release asks only for one of: ".implode(', ', self::KEYS).'.');
        }

        DB::statement(<<<'SQL'
            INSERT INTO release_rebuilds (rebuild, asked_at, queued_at) VALUES (?, CAST(? AS timestamptz), NULL)
            ON CONFLICT (rebuild) DO UPDATE SET asked_at = EXCLUDED.asked_at, queued_at = NULL
            SQL, [$rebuild, CarbonImmutable::now()->format('Y-m-d H:i:s.uP')]);
    }

    /**
     * The queued job that does $rebuild for $actor; null when this code
     * doesn't know it, as a worker still on the release before won't.
     */
    public function job(string $rebuild, JobActor $actor): ?ShouldQueue
    {
        return match ($rebuild) {
            'addresses' => new RecomputeAddresses($actor),
            'search' => new ReindexSearchJob($actor),
            default => null,
        };
    }
}

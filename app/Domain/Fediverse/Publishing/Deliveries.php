<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Publishing\Jobs\DeliverActivity;

/**
 * Puts queued deliveries on the queue, once the change that made them has
 * committed: one job each, delayed to its row's next attempt. Outside
 * production, unless allowed, they stay queued and no job is made.
 */
final class Deliveries
{
    /**
     * @param  list<string>  $deliveryIds
     */
    public function dispatch(array $deliveryIds): void
    {
        if ($deliveryIds === [] || ! self::environmentDelivers()) {
            return;
        }

        foreach (Delivery::query()->whereKey($deliveryIds)->where('state', 'queued')->get(['id', 'host', 'next_attempt_at']) as $delivery) {
            DeliverActivity::dispatch($delivery->id, $delivery->host)->delay($delivery->next_attempt_at->isFuture() ? $delivery->next_attempt_at : null);
        }
    }

    /**
     * Whether this site may deliver at all (D3): only from the host its
     * actors live on, so a copy at another address sends nothing, and
     * outside production only when the site's configuration says so.
     */
    public static function allowedFrom(string $actorUri): bool
    {
        $here = strtolower((string) parse_url(url('/'), PHP_URL_HOST));
        $theirs = strtolower((string) parse_url($actorUri, PHP_URL_HOST));

        if ($here === '' || $here !== $theirs) {
            return false;
        }

        return self::environmentDelivers();
    }

    /**
     * Whether the site runs where it delivers: production, or elsewhere
     * when its configuration allows it.
     */
    public static function environmentDelivers(): bool
    {
        return app()->environment('production') || (bool) config('teme.fediverse.deliver_outside_production', false);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

use App\Domain\Fediverse\Models\Activity;
use App\Domain\Hooks\HookDispatcher;
use Teme\Contracts\Fediverse\ActivitySent;

/**
 * Tells plugins granted fediverse.read what the site sent, after it is
 * stored and queued (docs/plans/fediverse-publishing.md, D5).
 */
final readonly class SentActivities
{
    public function __construct(private HookDispatcher $hooks) {}

    /**
     * @param  list<Activity>  $activities
     */
    public function announce(array $activities): void
    {
        if (! $this->hooks->wants(ActivitySent::class)) {
            return;
        }

        foreach ($activities as $activity) {
            $actor = $activity->payload['actor'] ?? null;
            $this->hooks->notify(new ActivitySent($activity->payload, $activity->type, is_string($actor) ? $actor : ''));
        }
    }
}

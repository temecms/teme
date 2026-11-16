<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing\Jobs;

use App\Domain\Fediverse\BlockedHosts;
use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Publishing\Deliveries;
use App\Domain\Fediverse\Publishing\DeliveryOutcome;
use App\Domain\Fediverse\Publishing\DeliveryPost;
use App\Domain\Fediverse\Publishing\DeliverySchedule;
use App\Domain\Fediverse\Publishing\Operations\Input\RecordDeliveryInput;
use App\Domain\Fediverse\Publishing\Operations\RecordDelivery;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Delivers one activity to one inbox (docs/plans/fediverse-publishing.md,
 * D3), as the site's own work: skipped for a blocked server, a server
 * failing for 7 days (tried once a day to learn it answers again); left
 * queued, untried, by a copy of the site at another address or outside
 * production; otherwise signed and posted, and RecordDelivery keeps what
 * came of it, putting a fresh job on the queue for the next try. At most
 * four deliveries reach one server at a time: each takes one of four locks
 * named for its host. A job may wait a day for its lock; one that gives up
 * puts its delivery back on the queue.
 */
final class DeliverActivity implements ShouldQueue
{
    use Queueable;

    /** How many deliveries reach one server at once. */
    public const int PER_HOST = 4;

    /** Minutes before a delivery whose job gave up is put back on the queue. */
    public const int REQUEUE_MINUTES = 15;

    public int $timeout = 30;

    public function __construct(
        public readonly string $deliveryId,
        public readonly string $host,
    ) {}

    /**
     * One of the host's four locks, by the delivery's ID, held while it posts.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        $slot = crc32($this->deliveryId) % self::PER_HOST;

        return [(new WithoutOverlapping('fediverse-host:'.$this->host.':'.$slot))->shared()->releaseAfter(5)->expireAfter(60)];
    }

    /**
     * How long the job may keep going back on the queue for its host's
     * lock: a day, after which failed() puts the delivery back.
     */
    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDay();
    }

    /**
     * Puts a delivery still queued back on the queue a little later, so a
     * job that gave up never leaves it waiting forever.
     */
    public function failed(?Throwable $exception = null): void
    {
        if (Deliveries::environmentDelivers() && Delivery::query()->whereKey($this->deliveryId)->where('state', 'queued')->exists()) {
            self::dispatch($this->deliveryId, $this->host)->delay(now()->addMinutes(self::REQUEUE_MINUTES));
        }
    }

    public function handle(DeliveryPost $post, Deliveries $deliveries): void
    {
        $previous = app(Elevation::class);
        app()->instance(Elevation::class, new OperatorElevation);

        try {
            $delay = $this->deliver($post);
        } finally {
            app()->instance(Elevation::class, $previous);
        }

        if ($delay !== null) {
            $deliveries->dispatch([$this->deliveryId]);
        }
    }

    /**
     * Tries the delivery and records what came of it; the seconds until the next try, or null.
     */
    private function deliver(DeliveryPost $post): ?int
    {
        $delivery = Delivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->state !== 'queued') {
            return null;
        }

        $activity = Activity::query()->find($delivery->activity_id);
        $actor = $activity === null ? null : FediverseActor::query()->find($activity->local_actor_id);

        // A copy of the site at another address, or outside production, sends nothing and records nothing.
        if ($actor !== null && ! Deliveries::allowedFrom($actor->uri)) {
            return null;
        }

        if ($activity === null || $actor === null || ! $actor->enabled) {
            $outcome = new DeliveryOutcome(DeliveryOutcome::SKIPPED, null, 'The account that sent it is gone.');
        } else {
            $outcome = $this->skipped($delivery) ?? $post->send($delivery->inbox_url, $activity->payload, $actor);
        }

        return app(RecordDelivery::class)->execute(new RecordDeliveryInput($delivery->id, $outcome));
    }

    /**
     * Why this delivery is skipped without a try, or null to try it.
     */
    private function skipped(Delivery $delivery): ?DeliveryOutcome
    {
        if (BlockedHosts::blocks($delivery->host)) {
            return new DeliveryOutcome(DeliveryOutcome::SKIPPED, null, 'The server is blocked.');
        }

        $failing = RemoteActor::query()->where('host', $delivery->host)->min('failing_since');
        $since = $failing === null ? null : CarbonImmutable::parse((string) $failing);

        // A server failing for a week is tried once a day, to learn whether it answers again.
        if ($since !== null && $since->lte(now()->subDays(DeliverySchedule::SKIP_AFTER_DAYS))
            && ! Cache::add('fediverse:probe:'.$delivery->host, true, DeliverySchedule::PROBE_SECONDS)) {
            return new DeliveryOutcome(DeliveryOutcome::SKIPPED, null, 'The server has been failing for '.DeliverySchedule::SKIP_AFTER_DAYS.' days.');
        }

        return null;
    }
}

<?php

/**
 * What one change queued for the fediverse: the activities stored, in the
 * order they go out, and the delivery rows made for them.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

use App\Domain\Fediverse\Models\Activity;

final readonly class QueuedActivities
{
    /**
     * @param  list<Activity>  $activities
     * @param  list<string>  $deliveryIds
     */
    public function __construct(
        public array $activities = [],
        public array $deliveryIds = [],
    ) {}

    /**
     * Whether nothing was queued.
     */
    public function isEmpty(): bool
    {
        return $this->activities === [];
    }
}

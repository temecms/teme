<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

use App\Domain\Fediverse\Models\Activity;

/**
 * Tells plugins what the site sent, after it is stored and queued.
 */
final class SentActivities
{
    /**
     * @param  list<Activity>  $activities
     */
    public function announce(array $activities): void
    {
        //
    }
}

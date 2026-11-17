<?php

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * An activity the site queued for its followers, sent after it is stored:
 * a Create, Update, Delete or Announce of an item, the Accept of a Follow,
 * or one a plugin sent through FediverseSender. Its deliveries go after.
 *
 * @since 1.0.0
 */
#[HookContract(capability: 'fediverse.read')]
final readonly class ActivitySent implements Notification
{
    /**
     * @param  array<string, mixed>  $activity  the activity as it is sent
     * @param  string  $type  its type, such as Create or Accept
     * @param  string  $actorUri  the address of the site's account that sends it
     *
     * @since 1.0.0
     */
    public function __construct(
        public array $activity,
        public string $type,
        public string $actorUri,
    ) {}
}

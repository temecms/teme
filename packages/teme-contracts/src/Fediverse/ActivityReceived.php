<?php

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * An activity another fediverse server sent the site, once its signature
 * was checked and its server is not blocked: a Like, an Announce, a Follow
 * or any other. Sent from the queued job that takes it, never for one
 * refused at the inbox.
 *
 * @since 1.0.0
 */
#[HookContract(capability: 'fediverse.read')]
final readonly class ActivityReceived implements Notification
{
    /**
     * @param  array<string, mixed>  $activity  the activity as it arrived, decoded from its JSON
     * @param  string  $type  its type, such as Like or Announce
     * @param  string  $actorUri  the address of the account that signed it
     *
     * @since 1.0.0
     */
    public function __construct(
        public array $activity,
        public string $type,
        public string $actorUri,
    ) {}
}

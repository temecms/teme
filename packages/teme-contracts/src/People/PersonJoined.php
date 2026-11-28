<?php

/**
 * An invited person joined the site by choosing their password. Carries their
 * account ID and display name only, never a role or email.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\People;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * An invited person joined the site by choosing their password. Carries
 * their account ID and display name only, never a role or email. Sent after
 * they join. Needs people.read.
 *
 * @since next
 */
#[HookContract(capability: 'people.read')]
final readonly class PersonJoined implements Notification
{
    /**
     * @param  string  $personId  the person's account ID
     * @param  string  $name  the name the site shows for them
     *
     * @since next
     */
    public function __construct(
        public string $personId,
        public string $name,
    ) {}
}

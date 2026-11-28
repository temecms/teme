<?php

/**
 * Someone was invited to the site: a new account waits for them to choose a
 * password. Carries their account ID and display name only, never a role or
 * email.
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
 * Someone was invited to the site: a new account waits for them to choose a
 * password. Carries their account ID and display name only, never a role or
 * email. Sent after the invitation is saved. Needs people.read.
 *
 * @since next
 */
#[HookContract(capability: 'people.read')]
final readonly class PersonInvited implements Notification
{
    /**
     * @param  string  $personId  the invited person's account ID
     * @param  string  $name  the name the site shows for them
     * @param  string|null  $actorId  the account ID of who invited them, or null when the operator did
     *
     * @since next
     */
    public function __construct(
        public string $personId,
        public string $name,
        public ?string $actorId,
    ) {}
}

<?php

/**
 * A category or tag was created.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Taxonomy;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * A category or tag was created. Sent after the change is saved. An import run does not send it yet.
 *
 * @since next
 */
#[HookContract(capability: 'content.read')]
final readonly class TermCreated implements Notification
{
    /**
     * @param  TermRef  $term  the term, as it is after the change (as it was before the row went, for a deletion)
     * @param  string|null  $actorId  the account ID of the person who created it, or null when no signed-in person did
     *
     * @since next
     */
    public function __construct(
        public TermRef $term,
        public ?string $actorId,
    ) {}
}

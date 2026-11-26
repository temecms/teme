<?php

/**
 * A category or tag was renamed, or its slug, parent or description changed.
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
 * A category or tag was renamed, or its slug, parent or description changed. Sent after the change is saved, never during an import run.
 *
 * @since next
 */
#[HookContract(capability: 'content.read')]
final readonly class TermUpdated implements Notification
{
    /**
     * @param  TermRef  $term  the term, as it is after the change (as it was before the row went, for a deletion)
     * @param  string|null  $actorId  the account ID of the person who changed it, or null when no signed-in person did
     *
     * @since next
     */
    public function __construct(
        public TermRef $term,
        public ?string $actorId,
    ) {}
}

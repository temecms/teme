<?php

/**
 * An item's terms changed in one taxonomy: terms were added to it or taken off it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Taxonomy;

use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * An item's terms changed in one taxonomy: terms were added to it or taken
 * off it. Sent once for each taxonomy that changed, after the change is
 * saved, and never for a save that left the item's terms as they were.
 * Terms deleted outright are not named as removed.
 *
 * @since next
 */
#[HookContract(capability: 'content.read')]
final readonly class TermAssigned implements Notification
{
    /**
     * @param  ItemRef  $item  the item, as it is after the change
     * @param  string  $taxonomy  the taxonomy whose terms changed, such as category
     * @param  list<string>  $added  the IDs of the terms added to the item in that taxonomy
     * @param  list<string>  $removed  the IDs of the terms taken off the item in that taxonomy
     * @param  string|null  $actorId  the account ID of the person who made the change, or null when no signed-in person did
     *
     * @since next
     */
    public function __construct(
        public ItemRef $item,
        public string $taxonomy,
        public array $added,
        public array $removed,
        public ?string $actorId,
    ) {}
}

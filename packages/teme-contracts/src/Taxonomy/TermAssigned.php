<?php

/**
 * An item's terms changed: terms were added to it or taken off it, in one or more taxonomies.
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
 * An item's terms changed: terms were added to it or taken off it. Sent once
 * for the item and the change, after the change is saved, with what moved in
 * each taxonomy that changed, and never for a save that left the item's terms
 * as they were. Terms deleted outright are not named as removed. It is not sent
 * during an import run.
 *
 * @since next
 */
#[HookContract(capability: 'content.read')]
final readonly class TermAssigned implements Notification
{
    /**
     * @param  ItemRef  $item  the item, as it is after the change
     * @param  array<string, array{added: list<string>, removed: list<string>}>  $changes  for each taxonomy that changed, keyed by its name such as category, the IDs of the terms added to the item and taken off it
     * @param  string|null  $actorId  the account ID of the person who made the change, or null when no signed-in person did
     *
     * @since next
     */
    public function __construct(
        public ItemRef $item,
        public array $changes,
        public ?string $actorId,
    ) {}
}

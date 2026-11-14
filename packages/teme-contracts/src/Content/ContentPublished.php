<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use DateTimeImmutable;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * An item became public, or its public revision changed: published by a
 * person, or by its schedule, acting for whoever scheduled it. Sent after
 * the change is saved, never during an import run.
 *
 * @since 0.5.0
 */
#[HookContract(capability: 'content.read')]
final readonly class ContentPublished implements Notification
{
    /**
     * @param  ItemRef  $item  the item, with its live address
     * @param  string  $revisionId  the revision now public
     * @param  bool  $first  true when the item was never published before
     * @param  DateTimeImmutable  $publishedAt  when it was first published, in UTC
     * @param  string|null  $actorId  the account ID of the person who published or scheduled it, or null when no signed-in person did
     *
     * @since 0.5.0
     */
    public function __construct(
        public ItemRef $item,
        public string $revisionId,
        public bool $first,
        public DateTimeImmutable $publishedAt,
        public ?string $actorId,
    ) {}
}

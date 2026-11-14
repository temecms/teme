<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use DateTimeImmutable;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * A revision of an item was scheduled to be published at a later time. When
 * that time comes, ContentPublished is sent. Sent after the change is saved,
 * never during an import run.
 *
 * @since 0.5.0
 */
#[HookContract(capability: 'content.read')]
final readonly class ContentScheduled implements Notification
{
    /**
     * @param  ItemRef  $item  the item
     * @param  string  $revisionId  the revision that will be published
     * @param  DateTimeImmutable  $publishAt  when it will be published, in UTC
     * @param  string|null  $actorId  the account ID of the person who scheduled it, or null when no signed-in person did
     *
     * @since 0.5.0
     */
    public function __construct(
        public ItemRef $item,
        public string $revisionId,
        public DateTimeImmutable $publishAt,
        public ?string $actorId,
    ) {}
}

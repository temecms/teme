<?php

/**
 * One unpublished item in ContentReader::queue(): the item, whose status says
 * where it waits, and when it is scheduled for.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use DateTimeImmutable;

/**
 * One unpublished item in ContentReader::queue(): the item, whose status
 * says where it waits, and when it is scheduled for.
 *
 * @since 1.0.0
 */
final readonly class QueuedItem
{
    /**
     * @param  DateTimeImmutable|null  $scheduledFor  when it is scheduled to publish, in UTC; null when it isn't scheduled
     *
     * @since 1.0.0
     */
    public function __construct(
        public ItemRef $item,
        public ?DateTimeImmutable $scheduledFor,
    ) {}
}

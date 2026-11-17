<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * Which unpublished items ContentReader::queue() lists, and from where.
 *
 * @since 1.0.0
 */
final readonly class QueueQuery
{
    /**
     * @param  list<string>  $statuses  any of draft, review and scheduled
     * @param  list<string>  $types  content-type keys, at most 10; empty for every type
     * @param  string|null  $after  the cursor QueuePage gave; null for the first page
     * @param  int  $limit  1 to 200
     *
     * @since 1.0.0
     */
    public function __construct(
        public array $statuses = ['draft', 'review', 'scheduled'],
        public array $types = [],
        public ?string $after = null,
        public int $limit = 100,
    ) {}
}

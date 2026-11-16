<?php

declare(strict_types=1);

namespace Teme\Contracts\Lists;

/**
 * One content item on a list page, for a column to key its own cache and
 * choose a value.
 *
 * @since 0.9.0
 */
final readonly class ListRow
{
    /**
     * @param  'draft'|'review'|'scheduled'|'published'  $status
     *
     * @since 0.9.0
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $language,
        public string $updatedAt,
        public bool $trashed = false,
    ) {}
}

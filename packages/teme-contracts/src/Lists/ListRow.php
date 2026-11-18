<?php

/**
 * One content item on a list page, for a column to key its own cache and
 * choose a value.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Lists;

/**
 * One content item on a list page, for a column to key its own cache and
 * choose a value.
 *
 * @since 1.0.0
 */
final readonly class ListRow
{
    /**
     * @param  'draft'|'review'|'scheduled'|'published'  $status
     * @param  string  $updatedAt  when the item last changed, as ISO 8601 with its offset
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $language,
        public string $updatedAt,
        public bool $trashed = false,
    ) {}
}

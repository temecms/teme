<?php

/**
 * One item's working revision: its parts by handle and slug, never a model.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use DateTimeImmutable;

/**
 * One item's working revision: its parts by handle and slug, never a model.
 * The owner's account ID is always on a draft read under content.read; the
 * owner's display name is only filled when the plugin was also granted
 * people.read. Never an email, role or any other detail about them.
 *
 * @since 1.0.0
 */
final readonly class ItemSnapshot
{
    /**
     * @param  string  $revisionId  the working revision's ID
     * @param  string  $slug  the last part of its address
     * @param  array<string, mixed>  $body  the Tiptap document
     * @param  array<string, mixed>  $fields  by field handle
     * @param  array<string, list<string>>  $terms  term slugs by taxonomy
     * @param  list<string>  $credits  account IDs the item credits, in order; empty when it credits its owner
     * @param  list<ItemRef>  $translations  the item's other language versions
     * @param  DateTimeImmutable|null  $scheduledFor  when it is scheduled to publish, in UTC; null when it isn't scheduled. Its status is the item's.
     * @param  string|null  $ownerId  the owner's account ID under content.read; null only when the item has no owner
     * @param  string|null  $ownerName  the owner's display name only when the plugin also has people.read; never an email or role
     *
     * @since 1.0.0
     * @since next Takes $ownerId and $ownerName.
     */
    public function __construct(
        public ItemRef $item,
        public string $revisionId,
        public string $slug,
        public array $body,
        public array $fields,
        public array $terms,
        public array $credits,
        public array $translations,
        public ?DateTimeImmutable $scheduledFor = null,
        public ?string $ownerId = null,
        public ?string $ownerName = null,
    ) {}
}

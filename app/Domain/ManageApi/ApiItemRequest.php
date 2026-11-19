<?php

/**
 * A new item as POST /api/manage/v1/items sent it, its shape already
 * checked: what ApiItems writes.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\ManageApi;

final readonly class ApiItemRequest
{
    /**
     * @param  array<string, list<string>>  $terms  taxonomy => term IDs
     * @param  array<string, mixed>  $fields  field handle => value
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $status,
        public ?string $slug = null,
        public array $terms = [],
        public ?string $featuredMedia = null,
        public ?int $authorId = null,
        public array $fields = [],
        public ?string $publishAt = null,
    ) {}
}

<?php

/**
 * What one page of the Comments screen's list asks for (1.8c): who is
 * looking, the view, the item it's narrowed to, the search, a page number or a
 * cursor either way, and the person's page size.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Comments\CommentStatus;

final readonly class CommentListRequest
{
    public function __construct(
        public int $viewerId,
        public string $view = CommentStatus::PENDING,
        public ?string $itemId = null,
        public ?string $search = null,
        public ?string $after = null,
        public ?string $before = null,
        public int $perPage = 50,
        /** The page asked for by number, 1 to ListPages::CAP; a cursor wins over it (I-3421). */
        public int $page = 1,
    ) {}
}

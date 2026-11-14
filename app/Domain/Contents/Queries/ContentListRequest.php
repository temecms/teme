<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

/**
 * What a content type's list asks for: the type, tab, sort, paging cursor and
 * filters, and who is looking — the Mine tab needs to know.
 */
final readonly class ContentListRequest
{
    /**
     * @param  array<string, string>  $terms  taxonomy key => term ID, one filter per taxonomy
     */
    public function __construct(
        public string $type,
        public string $tab = 'all',
        public string $sort = 'updated',
        public string $direction = 'desc',
        public ?string $after = null,
        public ?string $before = null,
        public ?string $search = null,
        /** Where the search looks: `title`, or `body`, the published text. */
        public string $searchIn = 'title',
        public ?int $authorId = null,
        public array $terms = [],
        public int $viewerId = 0,
        /** Rows per page: the person's `lists.per_page`, 25, 50 or 100. */
        public int $perPage = 50,
        /**
         * Items with an undecided review, whatever their status: `pending`
         * for all, `submitted` for the viewer's own. Anything else is ignored.
         */
        public ?string $review = null,
        /**
         * The Date filter's bounds as UTC instants, worked out in the site's
         * zone: its first instant, inclusive, and the first after it,
         * exclusive (I-3129). Either may be null.
         */
        public ?string $startsAt = null,
        public ?string $endsBefore = null,
        /**
         * Whether the rows carry their comment counts: the type takes comments
         * and they're switched on (1.8c's D21).
         */
        public bool $withComments = false,
        /** One of the site's languages, by its tag: its items only (I-3360). Anything else is ignored. */
        public ?string $language = null,
    ) {}
}

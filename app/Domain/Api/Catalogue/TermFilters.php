<?php

/**
 * What a list of terms was asked for (D5, D39), every parameter checked:
 * a public taxonomy, a parent (an ID, or `none` for the top level), a slug,
 * a language (I-3360), the page's size and the position after which to read.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api\Catalogue;

use App\Domain\Api\ApiQuery;
use App\Domain\Api\ApiWords;
use App\Domain\Api\Cursor;
use App\Domain\Api\InvalidParameters;
use App\Domain\Api\Live;
use App\Domain\Api\Ulids;

final readonly class TermFilters
{
    /** The sort a terms cursor names: by name, then ID. */
    public const string SORT = 'name';

    /** The longest slug a term has. */
    private const int SLUG_LENGTH = 200;

    /** The longest name a cursor may hold, as a term's name column does. */
    private const int NAME_LENGTH = 255;

    public function __construct(
        public ?string $taxonomy,
        public ?string $parent,
        public ?string $slug,
        public int $limit,
        public ?Cursor $after,
        public ?string $language = null,
    ) {}

    /**
     * The filters a request names.
     *
     * @throws InvalidParameters naming every parameter that doesn't fit
     */
    public static function from(ApiQuery $query): self
    {
        $problems = [];
        $taxonomy = $query->get('taxonomy');
        $parent = $query->get('parent');
        $slug = $query->get('slug');
        $given = $query->get('after');
        $after = null;
        $limit = ApiQuery::DEFAULT_LIMIT;

        if ($taxonomy !== null && ! in_array($taxonomy, Live::taxonomies(), true)) {
            $problems['taxonomy'] = ApiWords::badTaxonomy(Live::taxonomies());
        }

        if ($parent !== null && $parent !== 'none' && preg_match(Ulids::LOWER, $parent) !== 1) {
            $problems['parent'] = ApiWords::badParent();
        }

        if ($slug !== null && mb_strlen($slug) > self::SLUG_LENGTH) {
            $problems['slug'] = ApiWords::badSlug();
        }

        try {
            $limit = ApiQuery::limit($query->get('limit'));
        } catch (InvalidParameters $refused) {
            $problems += $refused->problems;
        }

        if ($given !== null) {
            $after = Cursor::read($given, self::SORT);

            // Cursor::read() checks the shape only: a name holds no NUL and at most 255 characters.
            if ($after === null || str_contains($after->key, "\0") || mb_strlen($after->key) > self::NAME_LENGTH) {
                $problems['after'] = ApiWords::badAfter();
                $after = null;
            }
        }

        $language = ApiQuery::language($query, $problems);

        if ($problems !== []) {
            throw new InvalidParameters($problems);
        }

        return new self($taxonomy, $parent, $slug, $limit, $after, $language);
    }
}

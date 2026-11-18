<?php

/**
 * One reading of a content list's address (I-140): the keys the type's list
 * knows and the site month or days they name, so the list screen and a run over the
 * list filter alike. It reads nothing from the database.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Types\ContentType;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Carbon\CarbonImmutable;

final readonly class ContentListFilters
{
    /**
     * @param  array<string, string>  $values  the keys kept, as the address had them
     * @param  array{0: string|null, 1: string|null}  $bounds  the Date filter's bounds as UTC instants
     * @param  list<string>  $taxonomyKeys  the type's taxonomies, whose keys carry a term ID
     */
    private function __construct(
        public array $values,
        private array $bounds,
        private array $taxonomyKeys,
    ) {}

    /**
     * Reads a list's query: each key the type's list knows with a non-empty
     * string value, then the month when it reads as `YYYY-MM`, in `$timezone`.
     *
     * @param  array<string, mixed>  $query
     */
    public static function read(array $query, ContentType $type, string $timezone): self
    {
        $taxonomyKeys = array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, app(TaxonomyRegistry::class)->forType($type->key));

        // One filter per taxonomy the type uses, under the taxonomy's key, so
        // ?category=…&tag=… keeps working on Posts.
        $keys = ['tab', 'sort', 'direction', 'search', 'in', 'author', 'review', 'language', ...$taxonomyKeys];
        $values = [];

        foreach ($keys as $key) {
            $value = $query[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $values[$key] = $value;
            }
        }

        // One Date value (I-3129): the site's month, or a range of its days; the month wins.
        $month = self::monthBounds(is_string($query['month'] ?? null) ? $query['month'] : '', $timezone);

        if ($month[0] !== null) {
            $values['month'] = (string) $query['month'];
            $bounds = $month;
        } else {
            [$bounds, $from, $to] = self::rangeBounds(
                is_string($query['from'] ?? null) ? $query['from'] : '',
                is_string($query['to'] ?? null) ? $query['to'] : '',
                $timezone,
            );

            if ($from !== null) {
                $values['from'] = $from;
            }

            if ($to !== null) {
                $values['to'] = $to;
            }
        }

        return new self($values, $bounds, $taxonomyKeys);
    }

    /** The tab the address names, `all` when it names none. */
    public function tab(): string
    {
        return $this->values['tab'] ?? 'all';
    }

    /**
     * The request the list answers: these values, for this viewer, at this
     * page size, on this page or between these cursors.
     */
    public function request(ContentType $type, int $viewerId, int $perPage, ?string $after = null, ?string $before = null, bool $withComments = false, int $page = 1): ContentListRequest
    {
        $terms = [];

        foreach ($this->taxonomyKeys as $key) {
            if (isset($this->values[$key])) {
                $terms[$key] = $this->values[$key];
            }
        }

        return new ContentListRequest(
            type: $type->key,
            tab: $this->tab(),
            sort: $this->values['sort'] ?? 'updated',
            direction: $this->values['direction'] ?? '',
            after: $after,
            before: $before,
            search: $this->values['search'] ?? null,
            searchIn: ($this->values['in'] ?? 'title') === 'body' ? 'body' : 'title',
            authorId: isset($this->values['author']) ? (int) $this->values['author'] : null,
            terms: $terms,
            viewerId: $viewerId,
            perPage: $perPage,
            review: $this->values['review'] ?? null,
            startsAt: $this->bounds[0],
            endsBefore: $this->bounds[1],
            withComments: $withComments,
            language: $this->values['language'] ?? null,
            page: $page,
        );
    }

    /**
     * Whether something besides the Date filter narrows the list: a search,
     * an author, a term, a review or a language. The months' counts are the tab's, so
     * they say nothing true of such a list (I-140).
     */
    public function narrowsBesideDate(): bool
    {
        return array_intersect_key($this->values, array_flip(['search', 'author', 'review', 'language', ...$this->taxonomyKeys])) !== [];
    }

    /**
     * From and To as the site's days, each kept only when it reads as
     * YYYY-MM-DD and is a real day: the first instant of From and of the day
     * after To, in UTC, and the two as kept. A To before its From is no
     * range at all.
     *
     * @return array{0: array{0: string|null, 1: string|null}, 1: string|null, 2: string|null}
     */
    private static function rangeBounds(string $from, string $to, string $timezone): array
    {
        $start = self::dayStart($from, $timezone);
        // The next day's own start: where the zone skips a midnight, the day begins at 01:00 and addDay() keeps that hour.
        $end = self::dayStart($to, $timezone)?->addDay()->startOfDay();

        if ($start !== null && $end !== null && $end <= $start) {
            return [[null, null], null, null];
        }

        return [
            [$start?->utc()->toIso8601String(), $end?->utc()->toIso8601String()],
            $start === null ? null : $from,
            $end === null ? null : $to,
        ];
    }

    /** The first instant of a YYYY-MM-DD day in the zone, or null for anything else. */
    private static function dayStart(string $day, string $timezone): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $matches) !== 1 || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $matches[1], (int) $matches[2], (int) $matches[3], 0, 0, 0, $timezone);
    }

    /**
     * The first instant of a `YYYY-MM` month in the site's zone, and of the
     * next, both in UTC; nulls for anything else, which is ignored, as the
     * media picker's month is. The range check matters: Carbon would read
     * month 13 as next January.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function monthBounds(string $month, string $timezone): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $matches) !== 1) {
            return [null, null];
        }

        $start = CarbonImmutable::create((int) $matches[1], (int) $matches[2], 1, 0, 0, 0, $timezone);

        return [$start->utc()->toIso8601String(), $start->addMonthNoOverflow()->utc()->toIso8601String()];
    }
}

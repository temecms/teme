<?php

/**
 * The content reader's list (docs/plans/plugin-sockets-3.md): published,
 * public items an anonymous visitor may open, newest first, one page at a
 * time by an opaque cursor, filtered by type, language, terms and date. One
 * statement for the page, then the items' addresses as Payloads makes them.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Api\Cursor;
use App\Domain\Api\Live;
use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\Payloads;
use App\Domain\Languages\SiteLanguages;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Teme\Contracts\Content\ItemPage;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Content\ItemRef;

final readonly class PublishedItems
{
    public const int MOST_ITEMS = 200;

    public const int MOST_TYPES = 10;

    public const int MOST_TERMS = 10;

    private const string SORT = 'newest';

    /** The cursor's moment, to the microsecond, so items published in the same second never fall between pages. */
    private const string MOMENT = 'Y-m-d\TH:i:s.u\Z';

    public function __construct(private SiteLanguages $languages) {}

    /**
     * Returns one page of items as hooks tell of them, with the cursor for
     * the next page, or null on the last.
     *
     * @throws InvalidArgumentException for a cursor this list didn't give, an unknown language, or a value outside the query's bounds
     */
    public function page(ItemQuery $query): ItemPage
    {
        $this->refuseOutOfBounds($query);
        [$afterAt, $afterId] = $query->after === null ? [null, null] : self::decode($query->after);
        $language = $query->language === null ? false : $this->languages->stored($query->language);

        /** @var Builder<Content> $items */
        $items = Content::query();
        Live::publicOnly(Live::apply($items->getQuery(), $query->types === [] ? null : $query->types));

        $rows = $items
            ->when($language !== false, fn (Builder $q) => $language === null ? $q->whereNull('contents.language') : $q->where('contents.language', $language))
            ->when($query->publishedSince !== null, fn (Builder $q) => $q->where('contents.published_at', '>=', CarbonImmutable::instance($query->publishedSince)))
            ->tap(fn (Builder $q) => self::withTerms($q->getQuery(), $query->terms))
            ->when($afterAt !== null, fn (Builder $q) => $q->whereRaw('(contents.published_at, contents.id) < (?::timestamptz, ?)', [$afterAt, $afterId]))
            ->select('contents.*')
            // Read as Postgres holds it, to the microsecond, for the cursor.
            ->selectRaw("to_char(contents.published_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') as cursor_at")
            ->orderByDesc('contents.published_at')
            ->orderByDesc('contents.id')
            ->limit($query->limit + 1)
            ->get();

        $more = $rows->count() > $query->limit;
        $page = $rows->take($query->limit)->values();
        $last = $page->last();

        return new ItemPage(
            $page->map(fn (Content $item): ItemRef => Payloads::item($item))->all(),
            $more && $last instanceof Content ? self::encode($last) : null,
        );
    }

    /**
     * Keeps items filed under any of the named terms of each taxonomy: one
     * EXISTS a taxonomy, on the published filing.
     *
     * @param  array<string, list<string>>  $terms
     */
    private static function withTerms(QueryBuilder $query, array $terms): void
    {
        foreach ($terms as $taxonomy => $slugs) {
            $query->whereExists(fn (QueryBuilder $filed) => $filed->selectRaw('1')
                ->from('content_terms')
                ->join('terms', 'terms.id', '=', 'content_terms.term_id')
                ->whereColumn('content_terms.content_id', 'contents.id')
                ->where('content_terms.scope', 'published')
                ->where('terms.taxonomy', (string) $taxonomy)
                ->whereIn('terms.slug', $slugs));
        }
    }

    private function refuseOutOfBounds(ItemQuery $query): void
    {
        if ($query->limit < 1 || $query->limit > self::MOST_ITEMS) {
            throw new InvalidArgumentException('A page lists 1 to '.self::MOST_ITEMS.' items.');
        }

        if (count($query->types) > self::MOST_TYPES) {
            throw new InvalidArgumentException('A query names at most '.self::MOST_TYPES.' types.');
        }

        $count = 0;

        foreach ($query->terms as $slugs) {
            $count += count($slugs);
        }

        if ($count > self::MOST_TERMS) {
            throw new InvalidArgumentException('A query names at most '.self::MOST_TERMS.' terms.');
        }
    }

    private static function encode(Content $last): string
    {
        return Cursor::after(self::SORT, (string) $last->getAttribute('cursor_at'), (string) $last->id);
    }

    /**
     * The moment and ID a cursor names.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException for anything this list couldn't have given
     */
    private static function decode(string $cursor): array
    {
        $position = Cursor::read($cursor, self::SORT);
        $moment = $position === null ? false : CarbonImmutable::createFromFormat(self::MOMENT, $position->key, 'UTC');

        if ($position === null || ! $moment instanceof CarbonImmutable || $moment->format(self::MOMENT) !== $position->key) {
            throw new InvalidArgumentException('That cursor wasn’t given by this list.');
        }

        return [$moment->format('Y-m-d H:i:s.uP'), $position->id];
    }
}

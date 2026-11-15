<?php

declare(strict_types=1);

namespace App\Domain\Support\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Numbered pages with a capped count for the admin's lists (I-3421): pages 1
 * to 50 are read by offset, the count stops at the cap so it costs the same
 * on any site, and past the cap the lists page by keyset as before (I-140).
 */
final class ListPages
{
    /** The last page reached by number; past it, Previous and Next page by keyset. */
    public const int CAP = 50;

    /**
     * The page a request asks for: a whole number from 1 to the cap, else 1.
     */
    public static function requested(mixed $page): int
    {
        if (is_int($page)) {
            $number = $page;
        } elseif (is_string($page) && preg_match('/^\d{1,3}$/', $page) === 1) {
            $number = (int) $page;
        } else {
            return 1;
        }

        return $number >= 1 && $number <= self::CAP ? $number : 1;
    }

    /**
     * How many rows the query matches, counting no further than one past the
     * cap's last row: the same filtered query, with nothing selected and no
     * order, so it counts nothing the list would not show. Returns at most
     * `CAP × perPage + 1`.
     */
    public static function count(Builder $query, int $perPage): int
    {
        $matching = (clone $query)->reorder()->limit(self::CAP * $perPage + 1);
        $matching->columns = null;
        $matching->bindings['select'] = [];
        $matching->selectRaw('1');

        return (int) DB::query()->fromSub($matching, 'capped')->count();
    }

    /**
     * What the pager draws for one page: its number (null past the cap), the
     * last numbered page, whether there are pages beyond it, the rows' places
     * (null past the cap), the total where it is known, and the keyset
     * cursors either side, used past the cap and by Next on the last
     * numbered page.
     *
     * @return array{number: int|null, last: int, more: bool, from: int|null, to: int|null, total: int|null, newer: string|null, older: string|null}
     */
    public static function describe(?int $page, int $perPage, int $rowsOnPage, ?int $exactTotal, int $cappedCount, ?string $newer, ?string $older): array
    {
        $count = $exactTotal ?? $cappedCount;
        $most = self::CAP * $perPage;
        $from = $page === null || $rowsOnPage === 0 ? null : ($page - 1) * $perPage + 1;

        return [
            'number' => $page,
            'last' => max(1, min(self::CAP, (int) ceil($count / max(1, $perPage)))),
            'more' => $count > $most,
            'from' => $from,
            'to' => $from === null ? null : $from + $rowsOnPage - 1,
            'total' => $exactTotal ?? ($cappedCount <= $most ? $cappedCount : null),
            'newer' => $newer,
            'older' => $older,
        ];
    }
}

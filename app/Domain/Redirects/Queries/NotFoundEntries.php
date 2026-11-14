<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Queries;

use App\Domain\Redirects\RedirectLookup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The 404 log for its screen (I-298, I-871): a page of addresses at a time,
 * the most asked for first, each marked with the redirect that now answers it.
 */
final readonly class NotFoundEntries
{
    public function __construct(private RedirectLookup $redirects) {}

    /**
     * Returns a page of the log, at most `$limit` rows (1 to 200), the most
     * asked for first, then by address, after the row `$after` names; each
     * with the redirect that now answers it, and where the next page starts,
     * or null.
     *
     * @param  array{count: int, path: string}|null  $after
     *
     * @return array{rows: list<array{path: string, address: string, count: int, firstSeenAt: string, lastSeenAt: string, referrerHost: string|null, redirectId: string|null}>, next: array{count: int, path: string}|null}
     */
    public function mostAsked(int $limit, ?array $after = null): array
    {
        $limit = max(1, min(200, $limit));

        $rows = DB::table('not_found_log')
            ->when($after !== null, fn (Builder $query): Builder => $query
                // The bound alone first, so the index's range starts at the cursor rather than at its first row.
                ->where('count', '<=', (int) $after['count'])
                ->where(fn (Builder $page): Builder => $page
                    ->where('count', '<', (int) $after['count'])
                    ->orWhere('path', '>', (string) $after['path'])))
            ->orderByDesc('count')->orderBy('path')->limit($limit + 1)
            ->get(['path', 'count', 'first_seen_at', 'last_seen_at', 'referrer_host']);

        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $marks = $this->redirects->matchedAll($rows->pluck('path')->map(strval(...))->all());

        $list = $rows->map(fn (object $row): array => [
            'path' => (string) $row->path,
            'address' => '/'.$row->path,
            'count' => (int) $row->count,
            'firstSeenAt' => CarbonImmutable::parse((string) $row->first_seen_at)->toIso8601String(),
            'lastSeenAt' => CarbonImmutable::parse((string) $row->last_seen_at)->toIso8601String(),
            'referrerHost' => $row->referrer_host === null ? null : (string) $row->referrer_host,
            'redirectId' => $marks[(string) $row->path] ?? null,
        ])->all();

        $last = $list === [] ? null : $list[array_key_last($list)];

        return ['rows' => $list, 'next' => $more && $last !== null ? ['count' => $last['count'], 'path' => $last['path']] : null];
    }
}

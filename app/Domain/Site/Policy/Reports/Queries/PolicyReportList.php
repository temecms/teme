<?php

declare(strict_types=1);

namespace App\Domain\Site\Policy\Reports\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The policy reports table for the console and the doctor (I-3375): what
 * browsers said the public site's policy blocked or would block, lately.
 */
final readonly class PolicyReportList
{
    /**
     * The rows last seen at or after `$since`, newest first, at most `$limit`
     * (1 to 1,000), each with its directive, blocked origin or keyword,
     * page path, how the policy was sent, count and last sight.
     *
     * @return list<object{directive: string, blocked: string, page: string, disposition: string, count: int, last_seen_at: string}>
     */
    public function recent(CarbonImmutable $since, int $limit): array
    {
        /** @var list<object{directive: string, blocked: string, page: string, disposition: string, count: int, last_seen_at: string}> */
        return DB::table('csp_reports')
            ->select(['directive', 'blocked', 'page', 'disposition', 'count', 'last_seen_at'])
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(max(1, min(1000, $limit)))
            ->get()
            ->all();
    }

    /**
     * How many rows, each a distinct directive, blocked origin and page,
     * were last seen at or after `$since`.
     */
    public function countSince(CarbonImmutable $since): int
    {
        return DB::table('csp_reports')->where('last_seen_at', '>=', $since)->count();
    }
}

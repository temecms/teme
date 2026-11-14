<?php

declare(strict_types=1);

namespace App\Domain\Ai\Queries;

use App\Domain\Ai\UsageLimits;
use Carbon\CarbonImmutable;

/**
 * The figures above Tools › AI activity's list (I-1307): a month's spend, its requests and those with no price, and, for this month only, the budget and whether it is reached. Read from the monthly totals, never summed from the record.
 */
final readonly class AiMonthSummary
{
    public function __construct(private AiSpend $spend) {}

    /**
     * Returns the figures for a month (its first day, else this month): the
     * month and its words, whether it is this month, what it cost, how many
     * requests it held and how many of them had no price; and, for this month
     * only, the budget in micro-dollars (null when none is set) and whether
     * it is reached.
     *
     * @return array{month: string, label: string, current: bool, costMicrounits: int, requests: int, unpricedRequests: int, budgetMicrounits: int|null, reached: bool}
     */
    public function of(?string $month): array
    {
        $now = CarbonImmutable::now('UTC');
        $spent = $this->spend->month($month === null ? $now : CarbonImmutable::parse($month, 'UTC'));
        $current = $spent->month === AiSpend::monthOf($now);

        return [
            'month' => $spent->month,
            'label' => CarbonImmutable::parse($spent->month, 'UTC')->format('F Y'),
            'current' => $current,
            'costMicrounits' => $spent->costMicrounits,
            'requests' => $spent->requests,
            'unpricedRequests' => $spent->unpricedRequests,
            'budgetMicrounits' => $current ? UsageLimits::budgetMicrounits() : null,
            'reached' => $current && $this->spend->budgetReached(),
        ];
    }
}

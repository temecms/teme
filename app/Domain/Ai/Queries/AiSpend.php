<?php

/**
 * The month's AI spend, read from its own table and never summed from the
 * record (docs/superpowers/specs/2026-09-17-cms-design.md).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Queries;

use App\Domain\Ai\AiMonthSpend;
use App\Domain\Ai\UsageLimits;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class AiSpend
{
    /**
     * Returns the first day of the UTC month a moment falls in: '2026-11-01'.
     */
    public static function monthOf(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)->utc()->startOfMonth()->format('Y-m-d');
    }

    /**
     * Returns the month's total, this month's when no moment is given, or
     * zeros when nothing was recorded in it.
     */
    public function month(?CarbonInterface $at = null): AiMonthSpend
    {
        $month = self::monthOf($at ?? CarbonImmutable::now());
        $row = DB::table('ai_monthly_spend')->where('month', $month)->first(['cost_microunits', 'requests', 'unpriced_requests']);

        if ($row === null) {
            return new AiMonthSpend($month, 0, 0, 0);
        }

        return new AiMonthSpend($month, (int) $row->cost_microunits, (int) $row->requests, (int) $row->unpriced_requests);
    }

    /**
     * Returns whether this month's recorded cost has reached the budget;
     * false when there is no budget.
     */
    public function budgetReached(): bool
    {
        $budget = UsageLimits::budgetMicrounits();

        return $budget !== null && $this->month()->costMicrounits >= $budget;
    }
}

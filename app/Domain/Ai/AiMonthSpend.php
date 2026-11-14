<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What one UTC month of AI cost: the priced requests' total in micro-dollars,
 * every request, and those with no price (I-1302).
 */
final readonly class AiMonthSpend
{
    /**
     * @param  string  $month  the month's first day: '2026-11-01'
     */
    public function __construct(
        public string $month,
        public int $costMicrounits,
        public int $requests,
        public int $unpricedRequests,
    ) {}
}

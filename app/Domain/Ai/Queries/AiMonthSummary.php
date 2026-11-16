<?php

declare(strict_types=1);

namespace App\Domain\Ai\Queries;

use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Ai\UsageLimits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The figures above Tools › AI activity's list (I-1307): a month's spend, its requests and those with no price, and, for this month only, the budget and whether it is reached; and the month's totals by plugin. Read from the monthly totals, never summed from the record.
 */
final readonly class AiMonthSummary
{
    public function __construct(private AiSpend $spend, private PluginAiTasks $pluginTasks) {}

    /**
     * Returns each plugin's figures for a month (its first day, else this
     * month), by label: its code and label, what it cost, how many requests
     * it made and how many had no price. Plugins that asked nothing are left out.
     *
     * @return list<array{code: string, label: string, costMicrounits: int, requests: int, unpricedRequests: int}>
     */
    public function byPlugin(?string $month): array
    {
        $first = AiSpend::monthOf($month === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($month, 'UTC'));
        $rows = DB::table('ai_plugin_monthly_spend')->where('month', $first)->get(['plugin', 'cost_microunits', 'requests', 'unpriced_requests']);
        $labels = $rows->isEmpty() ? [] : $this->pluginTasks->labels();
        $totals = [];

        foreach ($rows as $row) {
            $code = (string) $row->plugin;
            $totals[] = [
                'code' => $code,
                'label' => $labels[$code]['label'] ?? $code,
                'costMicrounits' => (int) $row->cost_microunits,
                'requests' => (int) $row->requests,
                'unpricedRequests' => (int) $row->unpriced_requests,
            ];
        }

        usort($totals, fn (array $one, array $other): int => strnatcasecmp($one['label'], $other['label']));

        return $totals;
    }

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

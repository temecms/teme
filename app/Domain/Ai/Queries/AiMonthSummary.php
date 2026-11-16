<?php

declare(strict_types=1);

namespace App\Domain\Ai\Queries;

use App\Domain\Ai\Plugins\PluginAiSection;
use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Ai\UsageLimits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The figures above Tools › AI activity's list (I-1307): a month's spend, its requests and those with no price, and, for this month only, the budget and whether it is reached; and the month's totals by plugin. Read from the monthly totals, never summed from the record.
 */
final readonly class AiMonthSummary
{
    public function __construct(private AiSpend $spend, private PluginAiTasks $pluginTasks, private PluginAiSection $section) {}

    /**
     * Returns a month's spend by who asked (its first day, else this month),
     * for the By plugin table: Teme first, for people's own requests, then
     * each plugin that asked, by label. Each row has its code and label, its
     * cost, requests and unpriced requests, and, this month only, its budget:
     * the limit, what the limit counts and whether it is reached (Teme's is
     * the site's, which counts every request); null when it has none. Empty
     * when no plugin asked that month.
     *
     * @return list<array{code: string, label: string, costMicrounits: int, requests: int, unpricedRequests: int, budget: array{limitMicrounits: int, countedMicrounits: int, reached: bool}|null}>
     */
    public function byPlugin(?string $month): array
    {
        $now = CarbonImmutable::now('UTC');
        $first = AiSpend::monthOf($month === null ? $now : CarbonImmutable::parse($month, 'UTC'));
        $current = $first === AiSpend::monthOf($now);
        $rows = DB::table('ai_plugin_monthly_spend')->where('month', $first)->get(['plugin', 'cost_microunits', 'requests', 'unpriced_requests']);

        if ($rows->isEmpty()) {
            return [];
        }

        $labels = $this->pluginTasks->labels();
        $site = $this->spend->month(CarbonImmutable::parse($first, 'UTC'));
        $plugins = [];

        foreach ($rows as $row) {
            $code = (string) $row->plugin;
            $cost = (int) $row->cost_microunits;
            $limit = $current ? $this->section->budgetMicrounits($code) : null;
            $plugins[] = [
                'code' => $code,
                'label' => $labels[$code]['label'] ?? $code,
                'costMicrounits' => $cost,
                'requests' => (int) $row->requests,
                'unpricedRequests' => (int) $row->unpriced_requests,
                'budget' => $limit === null ? null : ['limitMicrounits' => $limit, 'countedMicrounits' => $cost, 'reached' => $cost >= $limit],
            ];
        }

        usort($plugins, fn (array $one, array $other): int => strnatcasecmp($one['label'], $other['label']));

        $siteLimit = $current ? UsageLimits::budgetMicrounits() : null;

        return [
            [
                'code' => AiActivityList::TEME,
                'label' => 'Teme',
                'costMicrounits' => max(0, $site->costMicrounits - array_sum(array_column($plugins, 'costMicrounits'))),
                'requests' => max(0, $site->requests - array_sum(array_column($plugins, 'requests'))),
                'unpricedRequests' => max(0, $site->unpricedRequests - array_sum(array_column($plugins, 'unpricedRequests'))),
                'budget' => $siteLimit === null ? null : ['limitMicrounits' => $siteLimit, 'countedMicrounits' => $site->costMicrounits, 'reached' => $this->spend->budgetReached()],
            ],
            ...$plugins,
        ];
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

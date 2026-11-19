<?php

/**
 * Each plugin's AI spend this UTC month, read from its own table and never
 * summed from the record, as the site's month is.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Plugins;

use App\Domain\Ai\AiMonthSpend;
use App\Domain\Ai\Queries\AiSpend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class PluginAiSpend
{
    /**
     * Returns the plugin's total this month, or zeros when it asked nothing.
     */
    public function month(string $code): AiMonthSpend
    {
        return $this->all()[$code] ?? new AiMonthSpend(AiSpend::monthOf(CarbonImmutable::now()), 0, 0, 0);
    }

    /**
     * Returns every plugin's total this month, by code, those that asked
     * nothing left out.
     *
     * @return array<string, AiMonthSpend>
     */
    public function all(): array
    {
        $month = AiSpend::monthOf(CarbonImmutable::now());
        $totals = [];

        foreach (DB::table('ai_plugin_monthly_spend')->where('month', $month)->orderBy('plugin')->get(['plugin', 'cost_microunits', 'requests', 'unpriced_requests']) as $row) {
            $totals[(string) $row->plugin] = new AiMonthSpend($month, (int) $row->cost_microunits, (int) $row->requests, (int) $row->unpriced_requests);
        }

        return $totals;
    }
}

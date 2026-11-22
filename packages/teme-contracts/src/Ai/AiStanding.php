<?php

/**
 * Whether a task can be asked now, why not in plain words, and this plugin's
 * month.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Ai;

/**
 * Whether a task can be asked now, why not in plain words, and this plugin's
 * month.
 *
 * @since 1.0.0
 */
final readonly class AiStanding
{
    /**
     * @param  string  $reason  one of: ready, ai-off, plugin-off, no-model, model-lacks, site-budget, plugin-budget, hourly-limit, no-task, task-waiting
     * @param  string  $sentence  why, in one plain sentence a person may read; empty when ready
     * @param  int|null  $monthBudgetMicrodollars  null when the plugin has no budget of its own
     *
     * @since 1.0.0
     */
    public function __construct(
        public bool $available,
        public string $reason,
        public string $sentence,
        public int $monthCostMicrodollars,
        public int $monthRequests,
        public ?int $monthBudgetMicrodollars,
    ) {}
}

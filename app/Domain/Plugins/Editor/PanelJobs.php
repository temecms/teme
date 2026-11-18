<?php

/**
 * A panel's work on one item (docs/proposals/plugin-sockets-3.md): the
 * plugin jobs its actions queued for it that wait or run, and those that
 * ended in the last 15 minutes, newest first, each with its label, state,
 * progress and message.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use App\Domain\Jobs\JobState;
use App\Domain\Plugins\Jobs\PluginJobKind;
use Illuminate\Support\Facades\DB;

final class PanelJobs
{
    /** How long an ended job stays in the panel, in minutes. */
    public const int RECENT_MINUTES = 15;

    /** The most jobs the panel shows. */
    public const int MOST = 5;

    /**
     * @return list<array{id: string, label: string, state: string, done: int, total: int|null, message: string|null}>
     */
    public static function of(string $code, string $panel, string $itemId): array
    {
        return DB::table('tracked_jobs')
            ->where('owner', $code)
            ->where('run_type', PluginJobKind::KEY)
            ->whereRaw("payload->>'panel' = ?", [$panel])
            ->whereRaw("payload->>'itemId' = ?", [$itemId])
            ->where(fn ($query) => $query
                ->whereIn('state', [JobState::Waiting->value, JobState::Running->value])
                ->orWhere('ended_at', '>=', now()->subMinutes(self::RECENT_MINUTES)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MOST)
            ->get(['id', 'label', 'state', 'done', 'total', 'message'])
            ->map(fn (object $job): array => [
                'id' => (string) $job->id,
                'label' => (string) $job->label,
                'state' => (string) $job->state,
                'done' => (int) $job->done,
                'total' => $job->total === null ? null : (int) $job->total,
                'message' => $job->message === null ? null : (string) $job->message,
            ])
            ->values()
            ->all();
    }

    /**
     * Whether any of these jobs still waits or runs, so the page asks again.
     *
     * @param  list<array{state: string}>  $jobs
     */
    public static function busy(array $jobs): bool
    {
        foreach ($jobs as $job) {
            if (in_array($job['state'], [JobState::Waiting->value, JobState::Running->value], true)) {
                return true;
            }
        }

        return false;
    }
}

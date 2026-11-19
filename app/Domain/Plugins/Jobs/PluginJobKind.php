<?php

/**
 * A plugin's declared job as a kind of tracked work (I-3445): retry queues a
 * fresh RunPluginJob with the saved payload; cancel drops nothing queued
 * beyond the Laravel job, and a running one stops at its next report.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Jobs;

use App\Domain\Jobs\JobKind;
use App\Domain\Jobs\JobRecord;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\JobActor;

final readonly class PluginJobKind implements JobKind
{
    public const string KEY = 'plugin-job';

    public function __construct(private PluginRegistry $registry) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function retry(JobRecord $job, ?string $actorId): void
    {
        $row = TrackedJob::query()->find($job->id);

        if ($row === null || $job->runId === null) {
            return;
        }

        $actor = JobActor::of($actorId);
        $long = $this->long($row->owner, $job->runId);
        $declared = $this->declared($row->owner, $job->runId);

        $queued = RunPluginJob::configure(
            new RunPluginJob($row->owner, $job->runId, $job->id, $actor),
            $declared ?? [
                'key' => $job->runId,
                'label' => $job->label,
                'long' => $long,
                'tries' => 3,
                'backoff' => DeclaredJobs::DEFAULT_BACKOFF,
            ],
        );

        $queued->onConnection($long ? 'database-long' : (string) config('queue.default'))
            ->onQueue($long ? 'long' : 'default');

        dispatch($queued);
    }

    public function cancel(JobRecord $job, ?string $actorId): void
    {
        // A running one stops at its next progress report (I-104).
    }

    private function long(string $code, string $key): bool
    {
        return ($this->declared($code, $key)['long'] ?? false) === true;
    }

    /**
     * @return array{key: string, label: string, long: bool, tries: int, backoff: list<int>}|null
     */
    private function declared(string $code, string $key): ?array
    {
        $name = PluginRecord::query()->where('code', $code)->value('name');

        if (! is_string($name)) {
            return null;
        }

        $manifest = $this->registry->find($name)?->manifest;

        return $manifest === null ? null : DeclaredJobs::of($manifest)->job($key);
    }
}

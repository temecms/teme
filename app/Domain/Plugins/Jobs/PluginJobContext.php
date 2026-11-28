<?php

/**
 * What a running plugin job may read and report (I-3445): its payload,
 * attempt and the person it acts for, and progress that stops the handler
 * when the run was cancelled or the plugin disabled.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Jobs;

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\Input\ReportJobProgressInput;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Support\Security\JobActor;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobStopped;

final class PluginJobContext implements JobContext
{
    private int $reported = 0;

    private ?string $summary = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $jobId,
        private readonly array $payload,
        private readonly int $attempt,
        private readonly string $pluginName,
        private readonly string $pluginCode,
        private readonly JobActor $actor,
        private readonly ReportJobProgress $report,
    ) {}

    public function payload(): array
    {
        return $this->payload;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function personId(): string
    {
        if ($this->actor->isOperator() || $this->actor->userId === null) {
            throw new NoPersonToActFor(__('This run has no person to act for.'));
        }

        return $this->actor->userId;
    }

    public function summary(string $message): void
    {
        $this->summary = $message;
    }

    /**
     * The closing line FinishJob should record: the summary, or "Finished."
     */
    public function closingMessage(): string
    {
        return $this->summary ?? __('Finished.');
    }

    public function permissions(): array
    {
        if ($this->actor->isOperator() || $this->actor->userId === null) {
            return [];
        }

        return AreaContexts::for($this->pluginCode, $this->actor->userId)->permissions;
    }

    public function progress(int $done, int $total, string $message = ''): void
    {
        $advanced = max(0, $done - $this->reported);
        $this->reported = max($this->reported, $done);

        $going = $this->report->execute(new ReportJobProgressInput(
            jobId: $this->jobId,
            advancedBy: $advanced,
            total: $total,
            message: $message === '' ? null : $message,
            actorId: $this->actor->userId,
        ));

        if (! $going) {
            throw new JobStopped('The job was cancelled.');
        }

        $row = TrackedJob::query()->find($this->jobId);
        $plugin = PluginRecord::query()->where('name', $this->pluginName)->first();

        if ($row === null || $row->state === JobState::Cancelled || $plugin === null || $plugin->state !== PluginRecord::ENABLED) {
            throw new JobStopped('The plugin is off.');
        }
    }
}

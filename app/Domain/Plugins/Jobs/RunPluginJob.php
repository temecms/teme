<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Jobs;

use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Jobs\Operations\Input\FinishJobInput;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SafeMode;
use App\Domain\Support\Security\JobActor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Teme\Contracts\Jobs\JobStopped;
use Teme\Contracts\Jobs\PluginHasJobs;
use Throwable;

/**
 * Core's one runner for a plugin's declared job (I-3445): builds the plugin
 * with its doors, runs the handler, tracks progress, and records success or
 * failure. Carries only scalars plus the actor.
 */
final class RunPluginJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /** Fits the default queue's retry window (tests/Architecture/QueueWindowsTest.php); long jobs raise this in configure(). */
    public int $timeout = 75;

    public function __construct(
        public readonly string $pluginCode,
        public readonly string $jobKey,
        public readonly string $trackedJobId,
        public readonly JobActor $actor,
    ) {}

    /**
     * @param  array{key: string, label: string, long: bool, tries: int, backoff: list<int>}  $declared
     */
    public static function configure(self $job, array $declared): self
    {
        $job->tries = $declared['tries'];
        $job->backoff = $declared['backoff'];
        $job->timeout = $declared['long'] ? 1800 : 120;

        return $job;
    }

    public function handle(
        PluginRegistry $registry,
        PluginLoader $loader,
        SafeMode $safeMode,
        CancelJob $cancel,
        FailJob $fail,
        FinishJob $finish,
        ReportJobProgress $report,
    ): void {
        $this->actor->bind();

        $record = PluginRecord::query()->where('code', $this->pluginCode)->first();
        $plugin = $record === null ? null : $registry->find($record->name);
        $manifest = $plugin?->manifest;
        $granted = in_array('jobs', $record->consent['capabilities'] ?? [], true);

        if ($record === null
            || $record->state !== PluginRecord::ENABLED
            || $plugin === null
            || $manifest === null
            || ! $granted
            || $safeMode->active()) {
            $cancel->execute(new CancelJobInput($this->trackedJobId, $this->actor->userId));

            return;
        }

        $declared = DeclaredJobs::of($manifest)->job($this->jobKey) ?? [
            'key' => $this->jobKey,
            'label' => $this->jobKey,
            'long' => false,
            'tries' => 1,
            'backoff' => [1],
        ];
        self::configure($this, $declared);

        $instance = $loader->instance($plugin, $record, $declared['long'] ? 300 : null);
        $handler = $instance instanceof PluginHasJobs ? ($instance->jobs()[$this->jobKey] ?? null) : null;

        if ($handler === null) {
            $this->tries = 1;
            $fail->execute(new FailJobInput($this->trackedJobId, "The plugin has no handler for {$this->jobKey}", $this->actor->userId));

            return;
        }

        $row = TrackedJob::query()->findOrFail($this->trackedJobId);
        $context = new PluginJobContext(
            $this->trackedJobId,
            $row->payload,
            max(1, $this->attempts()),
            $record->name,
            $this->actor,
            $report,
        );

        try {
            $handler->handle($context);
        } catch (JobStopped) {
            return;
        } catch (Throwable $failure) {
            report($failure);

            throw $failure;
        }

        $finish->execute(new FinishJobInput($this->trackedJobId, __('Finished.'), $this->actor->userId));
    }

    public function failed(?Throwable $exception): void
    {
        $this->actor->bind();

        $message = $exception === null ? __('It failed.') : self::firstLine($exception->getMessage());

        app(FailJob::class)->execute(new FailJobInput($this->trackedJobId, $message, $this->actor->userId));
    }

    private static function firstLine(string $message): string
    {
        $line = strtok(str_replace("\r\n", "\n", $message), "\n") ?: $message;

        return mb_substr($line, 0, 200);
    }
}

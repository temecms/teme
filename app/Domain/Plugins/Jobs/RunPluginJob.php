<?php

/**
 * Core's one runner for a plugin's declared job (I-3445): builds the plugin
 * with its doors, runs the handler, tracks progress, and records success or
 * failure. Carries only scalars plus the actor.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

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
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\PluginHttp;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SafeMode;
use App\Domain\Support\Security\JobActor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Jobs\JobFailed;
use Teme\Contracts\Jobs\JobStopped;
use Teme\Contracts\Jobs\PluginHasJobs;
use Throwable;

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
        $job->timeout = $declared['long'] ? 1800 : 75;

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

        $current = app(CurrentJobActor::class);
        $current->set($this->actor);

        try {
            $this->run($registry, $loader, $safeMode, $cancel, $fail, $finish, $report);
        } finally {
            $current->clear();
        }
    }

    /**
     * Runs the plugin's handler while the write doors know who queued the job.
     */
    private function run(
        PluginRegistry $registry,
        PluginLoader $loader,
        SafeMode $safeMode,
        CancelJob $cancel,
        FailJob $fail,
        FinishJob $finish,
        ReportJobProgress $report,
    ): void {
        $tracked = TrackedJob::query()->find($this->trackedJobId);

        if ($tracked === null || $tracked->state->ended()) {
            return;
        }

        $record = PluginRecord::query()->where('code', $this->pluginCode)->first();
        $plugin = $record === null ? null : $registry->find($record->name);
        $manifest = $plugin?->manifest;
        $granted = in_array('jobs', $record->consent['capabilities'] ?? [], true);

        if ($record === null
            || $record->state !== PluginRecord::ENABLED
            || $plugin === null
            || $manifest === null
            || ! $granted
            || ! Consent::covers($record->consent, $manifest)
            || $safeMode->active()) {
            try {
                $cancel->execute(new CancelJobInput($this->trackedJobId, $this->actor->userId));
            } catch (ValidationException) {
                // DisablePlugin or another cancel may have ended it after we loaded the row.
            }

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

        $instance = $loader->instance($plugin, $record, httpSeconds: $declared['long'] ? 300 : null, answerBytes: $declared['long'] ? PluginHttp::LONG_ANSWER_BYTES : null);
        $handler = $instance instanceof PluginHasJobs ? ($instance->jobs()[$this->jobKey] ?? null) : null;

        if ($handler === null) {
            $this->tries = 1;
            $fail->execute(new FailJobInput(
                $this->trackedJobId,
                __('The plugin has no handler for :key', ['key' => $this->jobKey]),
                $this->actor->userId,
            ));

            return;
        }

        $context = new PluginJobContext(
            $this->trackedJobId,
            $tracked->payload,
            max(1, $this->attempts()),
            $record->name,
            $record->code,
            $this->actor,
            $report,
        );

        try {
            $handler->handle($context);
        } catch (NoPersonToActFor $nobody) {
            // Another try would ask for the same person, so it fails now.
            $this->tries = 1;
            $fail->execute(new FailJobInput($this->trackedJobId, $nobody->getMessage(), $this->actor->userId));

            return;
        } catch (JobFailed $failed) {
            // The handler said this failure isn't worth another try.
            $this->tries = 1;
            $fail->execute(new FailJobInput($this->trackedJobId, self::failureMessage($failed->getMessage()), $this->actor->userId));

            return;
        } catch (JobStopped) {
            return;
        } catch (Throwable $failure) {
            report($failure);

            throw $failure;
        }

        // Plugin runs stay quiet on success; FailJob still tells the inbox.
        $finish->execute(new FinishJobInput($this->trackedJobId, $context->closingMessage(), $this->actor->userId, notify: false));
    }

    public function failed(?Throwable $exception): void
    {
        $this->actor->bind();

        app(FailJob::class)->execute(new FailJobInput(
            $this->trackedJobId,
            $exception === null ? __('It failed.') : self::failureMessage($exception->getMessage()),
            $this->actor->userId,
        ));
    }

    /**
     * The first line of a failure, cut at 200 characters, or "It failed." when blank.
     */
    private static function failureMessage(string $message): string
    {
        $line = self::firstLine($message);

        return $line === '' ? __('It failed.') : $line;
    }

    private static function firstLine(string $message): string
    {
        $line = strtok(str_replace("\r\n", "\n", $message), "\n");
        $line = is_string($line) ? trim($line) : trim($message);

        return mb_substr($line, 0, 200);
    }
}

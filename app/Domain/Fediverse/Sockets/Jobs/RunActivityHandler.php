<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets\Jobs;

use App\Domain\Fediverse\Sockets\InboxActivities;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Jobs\Operations\Input\FinishJobInput;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\SafeMode;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Teme\Contracts\Plugins\Capability;
use Throwable;

/**
 * Runs one plugin's handler for one inbox activity, tracked
 * (docs/plans/fediverse-publishing.md, follow-up R7): as the administrator
 * who enabled the plugin, with that person's own permissions and never
 * the operator's, only while the plugin is still enabled with
 * fediverse.read. The activity, the plugin and the handler are kept on the
 * tracked job.
 */
final class RunActivityHandler implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $trackedJobId) {}

    public function handle(): void
    {
        $job = TrackedJob::query()->find($this->trackedJobId);

        if ($job === null || $job->state->ended()) {
            return;
        }

        $payload = $job->payload;
        $plugin = is_string($payload['plugin'] ?? null) ? $payload['plugin'] : '';
        $record = PluginRecord::query()->where('name', $plugin)->first();
        $admin = $job->started_by === null ? null : User::query()->find($job->started_by);
        $actorId = $admin === null ? null : (string) $admin->id;

        if ($record === null || $record->state !== PluginRecord::ENABLED || $admin === null || $record->consented_by !== $admin->id
            || ! in_array(Capability::FediverseRead->value, $record->consent['capabilities'] ?? [], true) || app(SafeMode::class)->active()) {
            app(FailJob::class)->execute(new FailJobInput($job->id, __('The plugin can no longer take fediverse activities.'), $actorId));

            return;
        }

        Auth::setUser($admin);

        try {
            $ran = app(InboxActivities::class)->runHandler($plugin, (string) ($payload['handler'] ?? ''), (array) ($payload['activity'] ?? []), (string) ($payload['actor'] ?? ''));
        } catch (Throwable $failure) {
            report($failure);
            app(FailJob::class)->execute(new FailJobInput($job->id, __('The plugin’s handler failed.'), $actorId));

            return;
        } finally {
            Auth::forgetUser();
        }

        $ran
            ? app(FinishJob::class)->execute(new FinishJobInput($job->id, __('Finished.'), $actorId))
            : app(FailJob::class)->execute(new FailJobInput($job->id, __('The plugin no longer offers that handler.'), $actorId));
    }
}

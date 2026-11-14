<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Exceptions\SchedulerCannotPublishException;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\ScheduledPublicationInput;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Operations\OperationInput;

/**
 * Runs one claimed scheduled publication, as the person who scheduled it and
 * with their permission checked again now, through the same PublishRevision
 * a person would press. It locks its row and does nothing if the row is no
 * longer pending, so a second runner, or a slow run's reclaimed row, can
 * never publish twice.
 */
final class RunScheduledPublication extends BaseOperation
{
    /** The item's type, read as it is authorized, so perform() takes its tree first. */
    private ?string $type = null;

    protected function authorize(OperationInput $input): void
    {
        /** @var ScheduledPublicationInput $input */
        $schedule = ScheduledPublication::query()->findOrFail($input->scheduledPublicationId);
        $content = Content::withTrashed()->findOrFail($schedule->content_id);
        $this->type = (string) $content->type;
        $scheduler = $schedule->created_by === null ? null : (string) $schedule->created_by;

        // Only the scheduler publishes a schedule. The operator's console is
        // nobody, and publishing on its own authority would bypass the
        // permission this run re-checks.
        if ($input->actorId() !== $scheduler) {
            throw new AuthorizationException('Only the person who scheduled this publishes it.');
        }

        $actor = app(Actors::class)->find($scheduler);

        if ($actor === null) {
            throw new SchedulerCannotPublishException('scheduler_removed');
        }

        if (! Gate::forUser($actor)->allows('publish', $content)) {
            throw new SchedulerCannotPublishException('scheduler_cannot_publish');
        }
    }

    protected function validate(OperationInput $input): void {}

    /**
     * Whether it published, and how late it ran.
     *
     * @return array{published: bool, lateSeconds: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ScheduledPublicationInput $input */
        // The tree before any row, as the publish will take it (I-3363).
        app(SubtreeAddresses::class)->lock($this->type);
        $schedule = ScheduledPublication::query()->lockForUpdate()->findOrFail($input->scheduledPublicationId);

        if (! $schedule->isPending() || $schedule->held_reason !== null) {
            return ['published' => false, 'lateSeconds' => 0];
        }

        $content = Content::withTrashed()->lockForUpdate()->findOrFail($schedule->content_id);

        // A failed row is never claimed again: the trash is a person's
        // decision, and publishing over it would undo that.
        if ($content->trashed()) {
            $schedule->update(['failed_at' => Date::now(), 'failure_reason' => 'trashed']);

            return ['published' => false, 'lateSeconds' => 0];
        }

        // Complete before publishing, in the same transaction: PublishRevision
        // then finds no pending schedule to cancel, and a rollback un-marks it.
        $completedAt = Date::now();
        $schedule->update(['completed_at' => $completedAt]);

        // Resolved here, not injected, so a publish that fails fails this row
        // and never the run. It never clears an autosave: whoever scheduled
        // this may have typed since. Nobody is there to read a refusal, so a
        // page under it whose new address is taken keeps its old one.
        app(PublishRevision::class)->execute(new PublishRevisionInput(
            $content->id, (string) $schedule->revision_id, $input->actorId(), clearAutosave: false, checkRequired: false, keepClashingChildren: true,
        ));

        return [
            'published' => true,
            'lateSeconds' => max(0, (int) $schedule->run_at->diffInSeconds($completedAt)),
        ];
    }
}

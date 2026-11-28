<?php

/**
 * Runs one claimed scheduled publication, as the person who scheduled it and
 * with their permission checked again now, through the same PublishRevision
 * a person would press. It locks its row and does nothing if the row is no
 * longer pending, so a second runner, or a slow run's reclaimed row, can
 * never publish twice. A region whose code on the site changed since it was
 * scheduled is failed and its scheduler told, never published: code goes
 * live only when a person publishes it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Exceptions\SchedulerCannotPublishException;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\ScheduledPublicationInput;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Inbox\InboxLink;
use App\Domain\Inbox\Operations\Input\SendToInboxInput;
use App\Domain\Inbox\Operations\SendToInbox;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Teme\Contracts\Operations\OperationInput;

final class RunScheduledPublication extends BaseOperation
{
    /** The item's type, read as it is authorized, so perform() takes its tree first. */
    private ?string $type = null;

    public function __construct(private readonly SendToInbox $inbox) {}

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
     * Whether it published, how late it ran, and why it failed when it
     * failed for a reason its scheduler is told.
     *
     * @return array{published: bool, lateSeconds: int, failed?: string}
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

        // Code goes live with a password asked at that moment, which a run
        // can't ask (docs/architecture/security.md). The schedule was made
        // with the code as it was then; code that differs now goes live only
        // when a person publishes it, so the row fails and is not retried.
        if (PublishRevision::codeChanges($content, (string) $schedule->revision_id) !== []) {
            return $this->failForChangedCode($schedule, $content);
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
            $content->id, (string) $schedule->revision_id, $input->actorId(), clearAutosave: false, checkRequired: false, keepClashingChildren: true, askBeforeSave: false,
        ));

        return [
            'published' => true,
            'lateSeconds' => max(0, (int) $schedule->run_at->diffInSeconds($completedAt)),
        ];
    }

    /**
     * Fails the row because the region's code on the site changed after it
     * was scheduled, and tells the person who scheduled it.
     *
     * @return array{published: false, lateSeconds: int, failed: string}
     */
    private function failForChangedCode(ScheduledPublication $schedule, Content $content): array
    {
        $reason = ScheduledPublication::FAILURE_CODE_CHANGED;
        $schedule->update(['failed_at' => Date::now(), 'failure_reason' => $reason]);

        $this->inbox->execute(new SendToInboxInput(
            recipientId: (string) $schedule->created_by,
            kind: 'schedule.failed',
            title: __('Not published: :title', ['title' => Str::limit((string) $content->title, 200)]),
            text: __('The publication you scheduled didn’t run: :reason', ['reason' => ScheduledPublication::failureWords($reason)]),
            link: new InboxLink('content.edit', ['content' => (string) $content->id]),
            actorId: (string) $schedule->created_by,
        ));

        return ['published' => false, 'lateSeconds' => 0, 'failed' => $reason];
    }
}

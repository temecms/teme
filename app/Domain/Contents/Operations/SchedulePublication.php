<?php

/**
 * Schedules one revision of an article to be published at a chosen time, as
 * a row the scheduler claims (I-181, 4A), replacing any schedule the article
 * already has. A draft becomes scheduled; a live article stays published,
 * because a scheduled update changes nothing until it runs.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\BeforeSaveCheck;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Fields\RequiredFields;
use App\Domain\Hooks\Payloads;
use App\Domain\SiteCode\RegionCode;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\CoreTypes;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\ContentScheduled;
use Teme\Contracts\Operations\OperationInput;
use Throwable;

final class SchedulePublication extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var SchedulePublicationInput $input */
        $user = User::find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('publish', $content)) {
            throw new AuthorizationException('Not permitted to publish this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SchedulePublicationInput $input */
        if (! in_array($input->action, ['publish', 'update'], true)) {
            throw ValidationException::withMessages(['action' => __('A schedule publishes an item or updates a live one.')]);
        }

        $runAt = self::instant($input->runAt);

        if ($runAt === null || ! $runAt->isAfter(Date::now())) {
            throw ValidationException::withMessages(['runAt' => __('Choose a time in the future.')]);
        }

        $belongs = ContentRevision::query()
            ->whereKey($input->revisionId)
            ->where('content_id', $input->contentId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages(['revisionId' => __('That revision does not belong to this content.')]);
        }

        $type = (string) Content::query()->whereKey($input->contentId)->value('type');

        // Code goes live with a password asked at that moment, which a schedule can't ask (docs/architecture/security.md).
        if ($type === CoreTypes::REGION) {
            $content = Content::query()->findOrFail($input->contentId);
            $live = $content->status === 'published' && $content->published_revision_id !== null ? ContentRevision::query()->whereKey($content->published_revision_id)->value('document') : [];

            if (RegionCode::changes((array) ($live ?? []), (array) (ContentRevision::query()->whereKey($input->revisionId)->value('document') ?? [])) !== []) {
                throw ValidationException::withMessages(['runAt' => __('A change to Custom HTML code is published now, not on a schedule.')]);
            }
        }

        RequiredFields::check($type, (array) ContentRevision::query()->whereKey($input->revisionId)->value('fields'), RequiredFields::noun($type));

        // Asked before the transaction opens, so a refused schedule writes nothing (BeforeSaveCheck).
        $item = Content::query()->findOrFail($input->contentId);
        $saved = ContentRevision::query()->whereKey($input->revisionId)->first(['title', 'document', 'fields']);
        app(BeforeSaveCheck::class)->ask(
            (string) $item->id,
            $type,
            $item->language,
            (string) $saved?->title,
            (array) $saved?->document,
            (array) $saved?->fields,
            $input->actorId,
        );
    }

    /**
     * The new schedule's ID.
     */
    protected function perform(OperationInput $input): string
    {
        /** @var SchedulePublicationInput $input */
        $content = Content::lockForUpdate()->findOrFail($input->contentId);
        $runAt = (self::instant($input->runAt) ?? CarbonImmutable::now())->utc();

        // One pending schedule per article: a new one replaces the old, a
        // held one included, which is how rescheduling clears a hold.
        ScheduledPublication::cancelPendingFor($content->id, 'replaced');

        $schedule = ScheduledPublication::query()->create([
            'content_id' => $content->id,
            'revision_id' => $input->revisionId,
            'action' => $input->action,
            'run_at' => $runAt,
            'created_by' => (int) $input->actorId(),
        ]);

        $before = ContentState::of($content);

        $content->update([
            'scheduled_for' => $runAt,
            'status' => $content->status === 'published' ? 'published' : 'scheduled',
        ]);

        ContentCounts::apply($before, ContentState::of($content));

        $revisionId = (string) $input->revisionId;
        $actorId = $input->actorId();
        $this->notifyAfterCommit(ContentScheduled::class, fn (): ContentScheduled => new ContentScheduled(Payloads::item($content), $revisionId, Payloads::date($runAt), $actorId));

        return (string) $schedule->id;
    }

    /**
     * The time asked for, or null when it is not one.
     */
    private static function instant(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}

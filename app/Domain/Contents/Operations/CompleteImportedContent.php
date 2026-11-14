<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Cards\PlainText;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Input\CompleteImportedContentInput;
use App\Domain\Contents\SearchText;
use App\Domain\Fields\FieldValidator;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Media\MediaProjection;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Writes the import's second pass into an item it made (I-333): its
 * reusable blocks put in and its links finished, as one revision of the
 * import's, only while the item is as the import left it, since a person's
 * save here is never undone. Only an import's actor may call it.
 */
final class CompleteImportedContent extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CompleteImportedContentInput $input */
        $content = Content::withTrashed()->find($input->contentId);

        if ($content === null) {
            throw ValidationException::withMessages(['content' => 'The item to complete no longer exists.']);
        }

        if (preg_match('/^[0-9a-f]{40}$/', $input->written) !== 1) {
            throw ValidationException::withMessages(['written' => 'The import’s record of this item couldn’t be read.']);
        }

        app(FieldValidator::class)->validate((string) $content->type, $input->fields);
        app(DocumentValidator::class)->validate(DocumentNormaliser::normalise($input->document));
    }

    /**
     * @return array{changedHere: bool, wrote: bool, written: string, revisionId: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var CompleteImportedContentInput $input */
        $content = Content::withTrashed()->lockForUpdate()->findOrFail($input->contentId);
        $working = (string) $content->working_revision_id;

        // Checked under the lock: a person's save since the import wins (I-332).
        if (ImportedContents::fingerprint($content) !== $input->written) {
            return ['changedHere' => true, 'wrote' => false, 'written' => $input->written, 'revisionId' => $working];
        }

        $current = ContentRevision::query()->findOrFail($working);
        $document = DocumentNormaliser::normalise($input->document);
        $fields = app(FieldValidator::class)->validate((string) $content->type, $input->fields);

        // Loose comparison: JSONB gives a document's keys back in its own order.
        if ($current->document == $document && $current->fields == $fields) {
            return ['changedHere' => false, 'wrote' => false, 'written' => $input->written, 'revisionId' => $working];
        }

        $before = ContentState::of($content);
        // What the public saw of it, read under the lock (I-263).
        $shown = Footprints::content($content);

        $revision = ContentRevision::query()->create([
            'content_id' => $content->id,
            'document' => $document,
            'plain_text' => PlainText::of($document),
            'word_count' => PlainText::words($document),
            'fields' => $fields,
            'terms' => $current->terms,
            'title' => $current->title,
            'author_id' => $current->author_id,
            'origin' => 'import',
            'kind' => 'import',
            // WordPress's modified time, as the revision it finishes: the item's dates stay WordPress's.
            'created_at' => $current->created_at,
        ]);

        $content->timestamps = false;
        $content->forceFill([
            'fields' => $content->fields == $fields ? $content->fields : $fields,
            'working_revision_id' => $revision->id,
            'published_revision_id' => (string) $content->published_revision_id === $working ? $revision->id : $content->published_revision_id,
        ])->save();
        $content->timestamps = true;

        $this->follow($content, $working, (string) $revision->id, $input->actorId());

        ContentCounts::apply($before, ContentState::of($content));
        TermProjection::sync($content);
        MediaProjection::sync($content, $input->actorId());
        LinkProjection::sync($content);
        SearchText::sync($content);
        app(PathProjection::class)->syncContent($content);

        $content->refresh();
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [...$shown, ...Footprints::content($content)]));

        return ['changedHere' => false, 'wrote' => true, 'written' => ImportedContents::fingerprint($content), 'revisionId' => (string) $revision->id];
    }

    /**
     * A pending schedule or an open review of the revision it finished
     * moves to the finished one, or the schedule would publish, and an
     * approval publish, the words without their blocks.
     */
    private function follow(Content $content, string $from, string $to, ?string $actorId): void
    {
        $pending = ScheduledPublication::query()->where('content_id', $content->id)->where('revision_id', $from)
            ->whereNull('completed_at')->whereNull('failed_at')->whereNull('cancelled_at')->first();

        if ($pending !== null) {
            ScheduledPublication::cancelPendingFor((string) $content->id, 'replaced');
            ScheduledPublication::query()->create([
                'content_id' => $content->id,
                'revision_id' => $to,
                'action' => $pending->action,
                'run_at' => $pending->run_at,
                'created_by' => $pending->created_by,
                // A held schedule stays held, or the scheduler would claim it again.
                'held_reason' => $pending->held_reason,
            ]);
        }

        $open = ContentReview::query()->where('content_id', $content->id)->where('revision_id', $from)->whereNull('decided_at')->first();

        if ($open !== null) {
            ContentReview::closePendingFor((string) $content->id, 'withdrawn', $actorId);
            ContentReview::query()->create([
                'content_id' => $content->id,
                'revision_id' => $to,
                'submitted_by' => $open->submitted_by,
                'submitted_at' => $open->submitted_at,
            ]);
        }
    }
}

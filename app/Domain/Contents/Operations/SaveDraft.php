<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\PlainText;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\DraftChecks;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\SearchText;
use App\Domain\Contents\Slugs;
use App\Domain\Fields\FieldValidator;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\MediaProjection;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Operations\OperationInput;

/**
 * Writes a new working revision. Never touches published_revision_id, so a
 * published page keeps serving its published revision until an explicit
 * publish operation moves the pointer.
 */
final class SaveDraft extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var SaveDraftInput $input */
        $user = User::find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SaveDraftInput $input */
        DraftChecks::validate($input->contentId, $input->title, $input->document, $input->fields, $input->terms);
    }

    protected function perform(OperationInput $input): Content
    {
        /** @var SaveDraftInput $input */
        $content = Content::lockForUpdate()->findOrFail($input->contentId);
        $before = ContentState::of($content);

        if ($content->working_revision_id !== $input->expectedRevisionId) {
            throw StaleRevisionException::for(
                (string) $input->expectedRevisionId,
                $content->working_revision_id,
            );
        }

        $fields = app(FieldValidator::class)->validate($content->type, $input->fields, (array) ContentRevision::query()->whereKey($content->working_revision_id)->value('fields'));
        $title = trim($input->title);

        // The normalised document is what is stored; the input is readonly.
        $document = DocumentNormaliser::normalise($input->document);

        $revision = ContentRevision::create([
            'content_id' => $content->id,
            'document' => $document,
            'plain_text' => PlainText::of($document),
            'word_count' => PlainText::words($document),
            'fields' => $fields,
            'title' => $title,
            'author_id' => $input->actorId(),
            // The assistant's change, used by a person (I-317); every other save is theirs.
            'origin' => $input->kind === 'ai' ? 'ai' : 'human',
            'kind' => $input->restoredFromId === null ? $input->kind : 'restore',
            'restored_from_id' => $input->restoredFromId,
            'terms' => array_values(array_unique($input->terms)),
            // What it was translated from, carried forward: the restored revision's, else the one it replaces (I-3360).
            'source_revision_id' => ContentRevision::query()->whereKey($input->restoredFromId ?? $content->working_revision_id)->value('source_revision_id'),
            'via' => $input->via,
        ]);

        $content->update([
            'working_revision_id' => $revision->id,
            'title' => $title,
            'slug' => self::addressFromFirstTitle($content, $title) ?? $content->slug,
        ]);

        ContentCounts::apply($before, ContentState::of($content));

        TermProjection::sync($content);
        MediaProjection::sync($content, $input->actorId());
        LinkProjection::sync($content);
        SearchText::sync($content);

        $content->refresh();
        $revisionId = (string) $revision->id;
        $actorId = $input->actorId();
        $this->notifyAfterCommit(ContentSaved::class, fn (): ContentSaved => new ContentSaved(Payloads::item($content), $revisionId, false, $actorId));

        return $content;
    }

    /**
     * The address a draft started with no title takes from its first title,
     * or null to keep its own: only while it has never been published, its
     * title is empty and its address is still the one an empty title gave it
     * (I-425). A draft has no public address yet, so no path or redirect
     * follows the change.
     */
    private static function addressFromFirstTitle(Content $content, string $title): ?string
    {
        if ($title === '' || $content->status !== 'draft' || $content->published_at !== null || trim((string) $content->title) !== '') {
            return null;
        }

        if (preg_match('/^'.preg_quote(Slugs::fromTitle(''), '/').'(-[0-9]+)?$/', (string) $content->slug) !== 1) {
            return null;
        }

        // Slugs::unique() counts this draft's own row, so a title that makes
        // the address it already has keeps it rather than taking the next.
        $base = Slugs::fromTitle($title);

        return $base === $content->slug ? null : Slugs::unique((string) $content->type, $base, $content->language);
    }
}

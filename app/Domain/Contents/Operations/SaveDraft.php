<?php

/**
 * Writes a new working revision. Never touches published_revision_id, so a
 * published page keeps serving its published revision until an explicit
 * publish operation moves the pointer. A region's Custom HTML code changes
 * only by someone who may manage site code, with their password asked
 * again, and each change is logged (docs/architecture/security.md).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\PlainText;
use App\Domain\Contents\BeforeSaveCheck;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\DraftChecks;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\ItemTerms;
use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\SearchText;
use App\Domain\Contents\Slugs;
use App\Domain\Fields\FieldValidator;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\MediaProjection;
use App\Domain\SiteCode\RegionCode;
use App\Domain\SiteCode\RegionCodeLog;
use App\Domain\SiteCode\SiteCode;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Domain\Types\CoreTypes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class SaveDraft extends BaseOperation implements RequiresElevation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var SaveDraftInput $input */
        $user = User::find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }

        // Someone who may not change a region's code saves it as it was; anything else is refused.
        if ($content->type === CoreTypes::REGION && ! RegionCode::manages($user)) {
            RegionCode::carry($input->document, self::working($content), SiteCode::disallowed() ? SiteCode::SWITCHED_OFF : 'Not permitted to change site code.');
        }
    }

    /**
     * Saving a region whose Custom HTML code changed asks for the password
     * again, as saving site code does.
     */
    public function requiresElevation(OperationInput $input): bool
    {
        /** @var SaveDraftInput $input */
        $content = Content::query()->find($input->contentId);

        if ($content === null || $content->type !== CoreTypes::REGION) {
            return false;
        }

        $base = self::working($content);
        $document = DocumentNormaliser::normalise($input->document);

        if (! RegionCode::manages(User::find($input->actorId()))) {
            $document = RegionCode::carry($document, $base);
        }

        return RegionCode::changes($base, $document) !== [];
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SaveDraftInput $input */
        DraftChecks::validate($input->contentId, $input->title, $input->document, $input->fields, $input->terms);

        // Asked before the transaction opens, so a refusal writes nothing (BeforeSaveCheck).
        $content = Content::query()->findOrFail($input->contentId);
        app(BeforeSaveCheck::class)->ask(
            (string) $content->id,
            (string) $content->type,
            $content->language,
            trim($input->title),
            DocumentNormaliser::normalise($input->document),
            $input->fields,
            $input->actorId,
            $input->kind,
            $input->via,
        );
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
        $termsBefore = (array) ContentRevision::query()->whereKey($content->working_revision_id)->value('terms');
        $title = trim($input->title);

        // The normalised document is what is stored; the input is readonly.
        $document = DocumentNormaliser::normalise($input->document);
        $region = $content->type === CoreTypes::REGION;
        $base = $region ? self::working($content) : [];

        // Against the revision locked here, which the stale check pinned.
        if ($region && ! RegionCode::manages(User::find($input->actorId()))) {
            $document = RegionCode::carry($document, $base);
        }

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

        if ($region) {
            RegionCodeLog::saved(RegionCode::changes($base, $document), $input->actorId(), (string) $content->id, (string) $content->slug, (string) $revision->id);
        }

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
        ItemTerms::announce($content, $termsBefore, (array) $revision->terms, $actorId);
        $this->notifyAfterCommit(ContentSaved::class, fn (): ContentSaved => new ContentSaved(Payloads::item($content), $revisionId, false, $actorId));

        return $content;
    }

    /**
     * The item's working revision's document, or an empty one.
     *
     * @return array<mixed>
     */
    private static function working(Content $content): array
    {
        return (array) (ContentRevision::query()->whereKey($content->working_revision_id)->value('document') ?? []);
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

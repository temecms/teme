<?php

/**
 * Publishing is a pointer assignment. No content is rewritten, no revision
 * is destroyed, and the previously published revision remains addressable.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Concerns\FollowsTree;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\SearchText;
use App\Domain\Contents\Slugs;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Contents\Visibility;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\RequiredFields;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\MediaProjection;
use App\Domain\Regions\RegionRules;
use App\Domain\SiteCode\RegionCode;
use App\Domain\SiteCode\RegionCodeLog;
use App\Domain\SiteCode\SiteCode;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\CoreTypes;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class PublishRevision extends BaseOperation implements RequiresElevation
{
    use FollowsTree;
    use PurgesTranslations;

    protected function authorize(OperationInput $input): void
    {
        /** @var PublishRevisionInput $input */
        $user = app(Actors::class)->find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('publish', $content)) {
            throw new AuthorizationException('Not permitted to publish this content.');
        }

        // Code put on the site or changed there goes live only by someone who
        // may manage site code; taking it off, anyone who may publish the region.
        if (RegionCode::addsOrChanges(self::codeChanges($content, $input->revisionId)) && ! RegionCode::manages($user)) {
            throw new AuthorizationException(SiteCode::disallowed() ? SiteCode::SWITCHED_OFF : 'Only an administrator can publish this region: it changes its Custom HTML code.');
        }
    }

    /**
     * Publishing a region whose Custom HTML code changes on the site asks
     * for the password again (docs/architecture/security.md).
     */
    public function requiresElevation(OperationInput $input): bool
    {
        /** @var PublishRevisionInput $input */
        $content = Content::query()->find($input->contentId);

        return $content !== null && self::codeChanges($content, $input->revisionId) !== [];
    }

    /**
     * What publishing this revision of a region does to the code on the
     * site, card by card; none for any other item, or a revision not its own.
     *
     * @return list<array{kind: 'added'|'changed'|'removed', id: string, before: array{code: string, origins: list<string>}, after: array{code: string, origins: list<string>}}>
     */
    public static function codeChanges(Content $content, string $revisionId): array
    {
        if ($content->type !== CoreTypes::REGION) {
            return [];
        }

        $target = ContentRevision::query()->whereKey($revisionId)->where('content_id', $content->id)->value('document');
        $live = $content->published_revision_id === null || $content->status !== 'published' ? [] : ContentRevision::query()->whereKey($content->published_revision_id)->value('document');

        return RegionCode::changes((array) ($live ?? []), (array) ($target ?? []));
    }

    protected function validate(OperationInput $input): void
    {
        /** @var PublishRevisionInput $input */
        $belongs = ContentRevision::where('id', $input->revisionId)
            ->where('content_id', $input->contentId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'revisionId' => 'That revision does not belong to this content.',
            ]);
        }

        $content = Content::query()->findOrFail($input->contentId);
        $this->treeType = (string) $content->type;

        // A pending address is checked before any write, so a refused publish
        // never leaves the live address moved.
        if ($input->slug !== null) {
            Slugs::assertUsable((string) $content->type, $input->slug, (string) $content->id, $content->language);
        }

        // A region is held to its theme's declaration as it is now, which a
        // theme update may have narrowed since the revision was saved. Only
        // the region's limits: the revision already fitted the schema.
        $rules = RegionRules::forContent($content, publishing: true);

        if ($rules !== null) {
            $rules->check((array) ContentRevision::query()->whereKey($input->revisionId)->first(['document'])?->document);
        }

        // The address it is about to take must not be another live item's,
        // term's or author's; a history row is taken over.
        $revision = ContentRevision::query()->whereKey($input->revisionId)->first(['terms', 'fields']);
        $terms = array_map('strval', (array) $revision?->terms);
        $slug = $input->slug ?? (string) $content->slug;
        $address = Addresses::wouldHave($content, $slug, $terms, null, $content->published_at, ((array) $revision?->fields)[BuiltInFields::PRIMARY_CATEGORY] ?? null);
        $clash = $address === null ? null : Paths::conflict($address, 'content', (string) $content->id);

        if ($clash !== null) {
            throw ValidationException::withMessages(['slug' => $clash]);
        }

        // Nor may a page under it land on another's address, when a person is
        // there to be told; a schedule keeps such a page where it is (I-3363).
        // A page already on the site gives them the chain they have.
        if (! $input->keepClashingChildren && ! self::onSite($content) && app(ContentTypeRegistry::class)->get((string) $content->type)->hierarchical) {
            $published = clone $content;
            $published->status = 'published';
            $published->slug = $slug;
            $subtree = app(SubtreeAddresses::class);
            $childClash = $subtree->hasChildren($published) ? $subtree->clash($published, $subtree->childChain($published)) : null;

            if ($childClash !== null) {
                throw ValidationException::withMessages(['slug' => $childClash]);
            }
        }

        // A schedule was checked when it was made (I-3343).
        if ($input->checkRequired) {
            RequiredFields::check($content->type, (array) $revision?->fields, RequiredFields::noun($content->type));
        }
    }

    protected function perform(OperationInput $input): Content
    {
        /** @var PublishRevisionInput $input */
        // The address rides in this same transaction, before the pointer moves.
        if ($input->slug !== null) {
            app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
                contentId: $input->contentId,
                expectedRevisionId: $input->revisionId,
                actorId: $input->actorId(),
                slug: $input->slug,
            ));
        }

        $nests = $this->takeTree();
        $content = Content::lockForUpdate()->findOrFail($input->contentId);
        $revision = ContentRevision::findOrFail($input->revisionId);
        $this->noteChain($content, $nests);

        $before = ContentState::of($content);
        $code = self::codeChanges($content, (string) $revision->id);
        // What the public saw of it, read under the lock (I-263).
        $shown = Footprints::content($content);

        // Published by hand, a pending schedule has nothing left to do, and a
        // pending review is over: someone published while it waited.
        ScheduledPublication::cancelPendingFor($content->id, 'published');
        ContentReview::closePendingFor($content->id, 'published', $input->actorId());

        $first = $content->published_at === null;

        $content->update([
            'status' => 'published',
            'published_revision_id' => $revision->id,
            'published_at' => $content->published_at ?? now(),
            'fields' => $revision->fields,
            'scheduled_for' => null,
        ]);

        RegionCodeLog::published($code, $input->actorId(), (string) $content->id, (string) $content->slug, (string) $revision->id);
        ContentCounts::apply($before, ContentState::of($content));
        TermProjection::sync($content);
        MediaProjection::sync($content, $input->actorId());
        LinkProjection::sync($content);
        SearchText::sync($content);
        app(PathProjection::class)->syncContent($content);
        $this->followTree($content, $input->actorId());

        // The pages that showed it before and those that show it now.
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [...$shown, ...Footprints::content($content), ...self::groupTags($content)]));

        // Publishing never checkpoints. The actor's autosave goes only when it
        // was based on the revision just published; on any other base it is
        // newer work, and stays. After commit, so a rolled-back publish clears
        // nothing. A deferred publish clears nothing at all.
        if ($input->clearAutosave) {
            $actorId = (int) $input->actorId();
            DB::afterCommit(fn () => ContentAutosave::query()
                ->where('content_id', $content->id)
                ->where('user_id', $actorId)
                ->where('based_on_revision_id', $revision->id)
                ->delete());
        }

        $content->refresh();
        $revisionId = (string) $revision->id;
        $actorId = $input->actorId();
        $this->notifyAfterCommit(ContentPublished::class, fn (): ContentPublished => new ContentPublished(Payloads::item($content), $revisionId, $first, Payloads::date($content->published_at ?? now()), $actorId));

        return $content;
    }

    /**
     * Whether the item has an address now, so publishing it again leaves
     * the chain its children have as it is.
     */
    private static function onSite(Content $content): bool
    {
        return $content->status === 'published' && ! $content->trashed() && $content->visibility !== Visibility::Private;
    }
}

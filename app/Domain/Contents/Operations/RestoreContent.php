<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\GroupLock;
use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Concerns\FollowsTree;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\RestoredContent;
use App\Domain\Contents\SearchText;
use App\Domain\Contents\Slugs;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Hooks\Payloads;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Media\MediaProjection;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Content\ContentRestored;
use Teme\Contracts\Operations\OperationInput;

/**
 * Takes an item out of the trash with its status unchanged. If a live item
 * has taken its slug meanwhile, the restored item gets the next free suffix
 * and the result reports the change; if another version of its group now
 * holds its language, it leaves the group, and the result says so (I-3360).
 */
final class RestoreContent extends BaseOperation
{
    use FollowsTree;
    use PurgesTranslations;

    protected function authorize(OperationInput $input): void
    {
        /** @var ContentActionInput $input */
        $user = User::find($input->actorId());
        $content = Content::withTrashed()->findOrFail($input->contentId);

        $this->treeType = (string) $content->type;

        if ($user === null || ! Gate::forUser($user)->allows('delete', $content)) {
            throw new AuthorizationException('Not permitted to restore this content.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): RestoredContent
    {
        /** @var ContentActionInput $input */
        $nests = $this->takeTree();
        // Its group's rows too, after the tree lock: whether its language is free is read from them (I-3360).
        $rows = GroupLock::lock([$input->contentId]);
        $content = $rows->get($input->contentId) ?? Content::withTrashed()->findOrFail($input->contentId);
        $this->noteChain($content, $nests);

        if (! $content->trashed()) {
            return new RestoredContent($content->id, $content->slug, null);
        }

        $before = ContentState::of($content);
        // What the public saw of it, read under the lock (I-263).
        $shown = Footprints::content($content);
        $previousSlug = null;

        if (! Slugs::isAvailable($content->type, $content->slug, $content->id, $content->language)) {
            $previousSlug = $content->slug;
            $content->slug = Slugs::unique($content->type, (string) preg_replace('/-\d+$/', '', $content->slug), $content->language);
        }

        // A restored published item also needs a free address: while another
        // target holds the one it would take, it takes the next suffix.
        if ($content->status === 'published') {
            $base = (string) preg_replace('/-\d+$/', '', $content->slug);

            for ($n = 2; $this->addressTaken($content); $n++) {
                $previousSlug ??= $content->slug;
                $content->slug = $base.'-'.$n;
            }
        }

        // Another live version holds its language in its group: it comes back on its own (D11).
        $group = self::groupTags($content);
        $leftGroup = $content->translation_group !== null && $rows->contains(fn (Content $row): bool => $row->id !== $content->id
            && ! $row->trashed()
            && $row->translation_group === $content->translation_group
            && $row->language === $content->language);

        if ($leftGroup) {
            $content->translation_group = null;
        }

        $content->restore();

        ContentCounts::apply($before, ContentState::of($content));
        TermProjection::sync($content);
        MediaProjection::sync($content, $input->actorId());
        LinkProjection::sync($content);
        SearchText::sync($content);
        app(PathProjection::class)->syncContent($content);
        $this->followTree($content, $input->actorId());

        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [...$shown, ...Footprints::content($content), ...$group]));

        $actorId = $input->actorId();
        $this->notifyAfterCommit(ContentRestored::class, fn (): ContentRestored => new ContentRestored(Payloads::item($content), $actorId));

        return new RestoredContent($content->id, $content->slug, $previousSlug, $leftGroup, $leftGroup ? app(SiteLanguages::class)->label(app(SiteLanguages::class)->of($content->language)) : null);
    }

    /**
     * Whether the address the item would have, with its slug as it stands,
     * is another target's, or the site's own.
     */
    private function addressTaken(Content $content): bool
    {
        // The published revision's own list: trashing cleared the projection.
        $revision = ContentRevision::query()->whereKey($content->published_revision_id)->first(['terms', 'fields']);
        $terms = array_map('strval', (array) $revision?->terms);
        $address = Addresses::wouldHave($content, $content->slug, $terms, null, $content->published_at, ((array) $revision?->fields)[BuiltInFields::PRIMARY_CATEGORY] ?? null);

        return $address !== null && Paths::conflict($address, 'content', (string) $content->id) !== null;
    }
}

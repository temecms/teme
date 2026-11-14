<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\SitePages;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Concerns\FollowsTree;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Contents\Visibility;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Hooks\Payloads;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Content\ContentVisibilityChanged;
use Teme\Contracts\Operations\OperationInput;

/**
 * Sets who may read an item (I-1700): anyone; only whoever may edit it, in
 * the admin, its address answering "not found"; or whoever gives the
 * password it keeps sealed. For whoever may publish it, at once and outside
 * any revision, and never an edit.
 */
final class ChangeVisibility extends BaseOperation
{
    use FollowsTree;
    use PurgesTranslations;

    public function __construct(private readonly ContentTypeRegistry $types) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ChangeVisibilityInput $input */
        $user = app(Actors::class)->find($input->actorId());
        $content = Content::query()->findOrFail($input->contentId);

        $this->treeType = (string) $content->type;

        // Whoever may publish it (I-571): nothing here is reviewed, and it reaches readers at once.
        if ($user === null || ! Gate::forUser($user)->allows('publish', $content)) {
            throw new AuthorizationException('Not permitted to change this item’s visibility.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ChangeVisibilityInput $input */
        $content = Content::query()->findOrFail($input->contentId);

        if (! $this->types->has($content->type) || ! $this->types->get($content->type)->public) {
            throw ValidationException::withMessages(['visibility' => __('Items of this type have no page of their own, so they have no visibility.')]);
        }

        $wanted = Visibility::tryFrom($input->visibility);

        if ($wanted === null) {
            throw ValidationException::withMessages(['visibility' => __('Choose Public, Private or Password protected.')]);
        }

        if ($wanted === Visibility::Protected) {
            $problem = ReaderPasswords::problem($input->password ?? '');

            if ($problem !== null) {
                throw ValidationException::withMessages(['password' => $problem]);
            }
        }

        if ($wanted !== Visibility::Public) {
            // The home page and the posts page stay on the site, for everyone (docs/architecture/addresses.md).
            SitePages::refuseTakingOff($content->id, 'visibility');
        }

        // A private item coming back to the site, public or protected, takes the address it would have, unless another holds it now.
        if ($wanted !== Visibility::Private && $content->visibility === Visibility::Private && $content->status === 'published' && $content->published_revision_id !== null) {
            $revision = ContentRevision::query()->whereKey($content->published_revision_id)->first(['terms', 'fields']);
            $address = Addresses::wouldHave(
                $content,
                $content->slug,
                array_map('strval', (array) $revision?->terms),
                null,
                $content->published_at,
                ((array) $revision?->fields)[BuiltInFields::PRIMARY_CATEGORY] ?? null,
            );
            $clash = $address === null ? null : Paths::conflict($address, 'content', (string) $content->id);

            if ($clash !== null) {
                throw ValidationException::withMessages(['visibility' => $clash]);
            }

            // Nor may a page under it land on another's address (I-3363).
            if ($this->types->get($content->type)->hierarchical) {
                $shown = clone $content;
                $shown->visibility = $wanted;
                $subtree = app(SubtreeAddresses::class);
                $childClash = $subtree->hasChildren($shown) ? $subtree->clash($shown, $subtree->childChain($shown)) : null;

                if ($childClash !== null) {
                    throw ValidationException::withMessages(['visibility' => $childClash]);
                }
            }
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var ChangeVisibilityInput $input */
        $nests = $this->takeTree();
        $content = Content::query()->lockForUpdate()->findOrFail($input->contentId);
        $this->noteChain($content, $nests);
        $wanted = Visibility::from($input->visibility);
        $password = $wanted === Visibility::Protected ? (string) $input->password : null;

        if ($content->visibility === $wanted && ReaderPasswords::open($content) === $password) {
            return false;
        }

        $before = ContentState::of($content);
        // What the public saw of it, read under the lock (I-263).
        $shown = Footprints::content($content);
        $addressMoves = $content->visibility === Visibility::Private || $wanted === Visibility::Private;
        $from = $content->visibility->value;
        $formerUrl = $this->listening(ContentVisibilityChanged::class) ? Addresses::urlFor($content) : null;

        // The query builder, not save(), which would set updated_at: who may read it isn't an edit.
        DB::table('contents')->where('id', $content->id)->update([
            'visibility' => $wanted->value,
            'reader_password' => $password === null ? null : ReaderPasswords::seal($password),
        ]);
        $content->refresh();

        ContentCounts::apply($before, ContentState::of($content));

        if ($addressMoves) {
            // Terms first: an address with {category} reads the published terms.
            TermProjection::sync($content);
            app(PathProjection::class)->syncContent($content);
            $this->followTree($content, $input->actorId());
        }

        // Its page, its cards and the lists it is in, before and after: a protected
        // page stored while it was public, and a private one's every trace, go.
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [...$shown, ...Footprints::content($content), ...self::groupTags($content)]));

        // Only who may read it, never the password (I-1700).
        if ($from !== $wanted->value) {
            $to = $wanted->value;
            $actorId = $input->actorId();
            $this->notifyAfterCommit(ContentVisibilityChanged::class, fn (): ContentVisibilityChanged => new ContentVisibilityChanged(Payloads::item($content), $from, $to, $formerUrl, $actorId));
        }

        return true;
    }
}

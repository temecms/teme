<?php

/**
 * Quick edit and bulk edit: changes an item's title, slug, author, categories
 * and tags, or published date, and the fields it is given, such as the
 * featured image. On a published item with nothing else waiting the change
 * goes live, as WordPress's quick edit does; with unpublished changes
 * waiting it stays in the draft, so no one's unfinished work is published
 * by accident.
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
use App\Domain\Addresses\SitePages;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\AuthorSort;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentDetailsResult;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\SearchText;
use App\Domain\Contents\Slugs;
use App\Domain\Contents\Tree\ContentTree;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Contents\Tree\TreeLock;
use App\Domain\Contents\Tree\TreeRules;
use App\Domain\Feeds\FeedPath;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldValidator;
use App\Domain\Media\MediaProjection;
use App\Domain\Media\Models\Media;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Domain\Taxonomy\TermRules;
use App\Domain\Themes\TemplateChoice;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Actors;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class UpdateContentDetails extends BaseOperation
{
    use PurgesTranslations;

    protected function authorize(OperationInput $input): void
    {
        /** @var UpdateContentDetailsInput $input */
        $user = app(Actors::class)->find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }

        if ($input->authorId !== null && $input->authorId !== $content->author_id && ! $user->can(app(ContentTypeRegistry::class)->ability($content->type, 'edit.any'))) {
            throw new AuthorizationException('Not permitted to change who wrote this content.');
        }

        // A page's place shows on other people's pages, their children and
        // trails, so it is never an own-items right (I-3363).
        if ($input->place !== null && ! $user->can(app(ContentTypeRegistry::class)->ability($content->type, 'edit.any'))) {
            throw new AuthorizationException('Not permitted to move this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateContentDetailsInput $input */
        $content = Content::findOrFail($input->contentId);
        $known = (array) ContentRevision::query()->findOrFail($content->working_revision_id)->terms;

        // An added term in another language is refused by name (I-3360).
        TermRules::refuseOtherLanguage(array_values(array_diff($input->terms ?? [], $known)), $content->language);

        Validator::make(
            [
                'title' => $input->title === null ? null : trim($input->title),
                'slug' => $input->slug,
                'author' => $input->authorId,
                'terms' => $input->terms,
                'added' => array_values(array_diff($input->terms ?? [], $known)),
                'published_at' => $input->publishedAt,
            ],
            [
                'title' => ['nullable', 'string', 'min:1', 'max:512'],
                'slug' => ['nullable', 'string', 'max:200', 'regex:/^[\p{Ll}\p{Lo}\p{N}]+(?:-[\p{Ll}\p{Lo}\p{N}]+)*$/u'],
                'author' => ['nullable', Rule::exists('users', 'id')],
                'terms' => ['nullable', 'array', 'max:50'],
                'terms.*' => ['string', 'distinct'],
                'added.*' => [TermRules::usableBy($content->type, $content->language)],
                'published_at' => ['nullable', 'date'],
            ],
        )->validate();

        if ($input->slug !== null && ! Slugs::isAvailable($content->type, $input->slug, $content->id, $content->language)) {
            throw ValidationException::withMessages(['slug' => __('Another :type already uses this address.', ['type' => $content->type])]);
        }

        $parentId = $input->place === null ? $content->parent_id : $input->place->parentId;

        if ($input->place !== null) {
            $refusal = app(TreeRules::class)->refusal(self::withSlug($content, $input->slug), $parentId);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['parent' => $refusal]);
            }

            if ($input->place->position !== null && ($input->place->position < 0 || $input->place->position > 99999)) {
                throw ValidationException::withMessages(['position' => __('Order must be a whole number from 0 to 99,999.')]);
            }
        }

        // An address ending in a feed's name answers the parent's feed (app/Domain/Feeds/FeedPath.php).
        if ($input->slug !== null && $parentId !== null && in_array($input->slug, FeedPath::NAMES, true)) {
            throw ValidationException::withMessages(['slug' => __("A page under another page can't be called :slug: that address is kept for feeds. Choose another.", ['slug' => $input->slug])]);
        }

        $placeChanges = $input->place !== null && ($input->place->parentId !== $content->parent_id);
        $parentPath = $placeChanges ? app(ContentTree::class)->parentPath($parentId) : null;

        if ($input->publishedAt !== null && $content->status !== 'published') {
            throw ValidationException::withMessages(['published_at' => __('Only a published item has a published date to change.')]);
        }

        // A date ahead hides the item until then: never the home page or the
        // posts page, which stay until another is chosen (#36).
        if ($input->publishedAt !== null && CarbonImmutable::parse($input->publishedAt)->isFuture()) {
            SitePages::refuseTakingOff((string) $content->id, 'published_at');
        }

        $primaryGiven = is_array($input->fields) && array_key_exists(BuiltInFields::PRIMARY_CATEGORY, $input->fields);

        // After the per-type check, whose message names no item: a live
        // item's new address must not be another item's, term's or author's.
        if (! $content->trashed() && $content->status === 'published' && ($input->slug !== null || $input->authorId !== null || $input->terms !== null || $input->publishedAt !== null || $primaryGiven || $placeChanges)) {
            $address = Addresses::wouldHave(
                $content,
                $input->slug ?? $content->slug,
                $input->terms === null
                    ? DB::table('content_terms')->where('content_id', $content->id)->where('scope', 'published')->orderBy('position')->pluck('term_id')->map(fn (mixed $id): string => (string) $id)->all()
                    : array_values(array_unique($input->terms)),
                // A new owner is the item's credit only while it has no bylines (I-3366).
                $input->authorId === null || $input->authorId === (int) $content->author_id ? null : self::newOwnerSlug($content, $input->authorId),
                $input->publishedAt === null ? $content->published_at : CarbonImmutable::parse($input->publishedAt),
                $primaryGiven ? $input->fields[BuiltInFields::PRIMARY_CATEGORY] : ($content->fields[BuiltInFields::PRIMARY_CATEGORY] ?? null),
                $parentPath,
            );
            $clash = $address === null ? null : Paths::conflict($address, 'content', (string) $content->id);

            if ($clash !== null) {
                throw ValidationException::withMessages(['slug' => $clash]);
            }
        }

        // Whatever its own status, the pages under it that have an address
        // move with a new slug or place: none may land on another's (I-3363).
        if (($placeChanges || ($input->slug !== null && $input->slug !== $content->slug)) && app(ContentTypeRegistry::class)->get($content->type)->hierarchical) {
            $subtree = app(SubtreeAddresses::class);
            $moved = self::withSlug($content, $input->slug);
            $moved->parent_id = $parentId;
            $clash = $subtree->clash($moved, $subtree->childChain($moved));

            if ($clash !== null) {
                throw ValidationException::withMessages(['slug' => $clash]);
            }
        }

        if ($input->fields !== null) {
            $currentFields = (array) ContentRevision::query()->findOrFail($content->working_revision_id)->fields;
            app(FieldValidator::class)->validate($content->type, $input->fields, $currentFields);
            TemplateChoice::check($content->type, $input->fields, $currentFields);

            foreach (MediaProjection::refsFor($content->type) as $key) {
                $id = $input->fields[$key]['id'] ?? null;
                $unchanged = $id === ($currentFields[$key]['id'] ?? null);

                // Only a newly chosen image must be live: quick edit re-sends
                // the current choice, which may have been trashed since.
                if (is_string($id) && $id !== '' && ! $unchanged && ! Media::query()->whereKey($id)->exists()) {
                    throw ValidationException::withMessages(["fields.{$key}" => __('That image is in the trash. Restore it, or choose another.')]);
                }
            }
        }
    }

    protected function perform(OperationInput $input): ContentDetailsResult
    {
        /** @var UpdateContentDetailsInput $input */
        // The tree before any row, so two moves can't make a loop (I-3363).
        $type = (string) Content::query()->whereKey($input->contentId)->value('type');
        $nests = $type !== '' && app(ContentTypeRegistry::class)->get($type)->hierarchical;

        if ($nests && ($input->slug !== null || $input->place !== null)) {
            TreeLock::take($type);
        }

        $content = Content::lockForUpdate()->findOrFail($input->contentId);

        if ($content->working_revision_id !== $input->expectedRevisionId) {
            throw StaleRevisionException::for($input->expectedRevisionId, $content->working_revision_id);
        }

        $parentChanges = $input->place !== null && $input->place->parentId !== $content->parent_id;
        $positionChanges = false;

        if ($input->place !== null) {
            $refusal = app(TreeRules::class)->refusal(self::withSlug($content, $input->slug), $input->place->parentId);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['parent' => $refusal]);
            }
        }

        $subtree = app(SubtreeAddresses::class);
        $chainBefore = $nests ? $subtree->childChain($content) : '';

        $before = ContentState::of($content);
        // What the public saw of it, read under the lock (I-263).
        $shown = Footprints::content($content);
        // The kept person when Bulk Edit keeps them, with their roles, so the
        // publish check below reloads nothing (B-201).
        $actor = app(Actors::class)->findOrFail($input->actorId());
        $nothingWaiting = $content->status === 'published' && $content->working_revision_id === $content->published_revision_id;

        // Only a change to what an address is made of moves it: Bulk Edit
        // runs this per item and is already over budget (B-201).
        $addressMayChange = $parentChanges
            || ($input->slug !== null && $input->slug !== $content->slug)
            || ($input->authorId !== null && $input->authorId !== (int) $content->author_id)
            || ($input->terms !== null && array_values(array_unique($input->terms)) !== (array) ContentRevision::query()->whereKey($content->working_revision_id)->value('terms'))
            || $input->publishedAt !== null
            || (is_array($input->fields) && array_key_exists(BuiltInFields::PRIMARY_CATEGORY, $input->fields));

        // What the public sees changes when the new revision goes live
        // below, or when the live item's slug, author or date change.
        $liveDetailsChange = $parentChanges
            || ($input->slug !== null && $input->slug !== $content->slug)
            || ($input->authorId !== null && $input->authorId !== (int) $content->author_id)
            || $input->publishedAt !== null;
        $publishedNow = false;

        if ($input->title !== null || $input->terms !== null || $input->fields !== null) {
            $current = ContentRevision::findOrFail($content->working_revision_id);
            $fields = $input->fields === null
                ? $current->fields
                : array_merge((array) $current->fields, app(FieldValidator::class)->validate($content->type, $input->fields, (array) $current->fields));

            $revision = ContentRevision::create([
                'content_id' => $content->id,
                'document' => $current->document,
                'plain_text' => $current->plain_text,
                'word_count' => $current->word_count,
                'fields' => $fields,
                'terms' => $input->terms === null ? $current->terms : array_values(array_unique($input->terms)),
                'title' => $input->title === null ? $current->title : trim($input->title),
                'author_id' => $actor->id,
                'origin' => 'human',
                // Still translated from the same revision (I-3360).
                'source_revision_id' => $current->source_revision_id,
            ]);

            $content->working_revision_id = $revision->id;
            $content->title = $revision->title;

            if ($nothingWaiting && Gate::forUser($actor)->allows('publish', $content)) {
                $content->published_revision_id = $revision->id;
                $content->fields = $revision->fields;
                $publishedNow = true;
            }
        }

        if ($input->slug !== null) {
            $content->slug = $input->slug;
        }

        if ($input->authorId !== null) {
            $content->author_id = $input->authorId;
            $content->author_sort = AuthorSort::of(User::query()->whereKey($input->authorId)->value('name'));
        }

        if ($input->publishedAt !== null) {
            $content->published_at = $input->publishedAt;
        }

        if ($input->place !== null) {
            $position = $input->place->position ?? ($parentChanges ? self::nextPosition($content, $input->place->parentId) : $content->position);
            $positionChanges = $position !== $content->position;
            $content->parent_id = $input->place->parentId;
            $content->position = $position;
        }

        $content->save();

        ContentCounts::apply($before, ContentState::of($content));
        TermProjection::sync($content);
        MediaProjection::sync($content, $input->actorId());
        LinkProjection::sync($content);
        SearchText::sync($content);

        if ($addressMayChange) {
            app(PathProjection::class)->syncContent($content);
        }

        // The pages under it follow its new slug or place (I-3363).
        if ($nests) {
            foreach ($subtree->follow($content, $chainBefore, $input->actorId())->purges as $purge) {
                $this->purgeAfterCommit($purge);
            }
        }

        // A change waiting in a draft tells nothing: the public can't see it.
        if ($publishedNow || $liveDetailsChange || $positionChanges) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [...$shown, ...Footprints::content($content), ...self::groupTags($content)]));
        }

        return new ContentDetailsResult(
            $content->id,
            publishedNow: $publishedNow,
            draftPending: $content->status === 'published' && ! $nothingWaiting,
        );
    }

    /**
     * The {author} value a new owner gives the item: their slug while the
     * item has no bylines, read only when its pattern holds {author}; null,
     * the item's own first credit, when it has bylines, which a new owner
     * doesn't change (I-3366).
     */
    private static function newOwnerSlug(Content $content, int $ownerId): ?string
    {
        $slug = Addresses::ownerSlug($content, $ownerId);

        return $slug === null || DB::table('content_bylines')->where('content_id', $content->id)->exists() ? null : $slug;
    }

    /**
     * The item as it would be with this slug, for the tree's rules; the
     * row itself is untouched.
     */
    private static function withSlug(Content $content, ?string $slug): Content
    {
        $probe = clone $content;

        if ($slug !== null) {
            $probe->slug = $slug;
        }

        return $probe;
    }

    /**
     * Last among the siblings under this parent, or at the top: the largest
     * position there plus one, or 0 when there are none.
     */
    private static function nextPosition(Content $content, ?string $parentId): int
    {
        $largest = Content::query()
            ->where('type', $content->type)
            ->where('id', '<>', $content->id)
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->max('position');

        return $largest === null ? 0 : (int) $largest + 1;
    }
}

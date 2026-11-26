<?php

/**
 * Moves one item to another site language (I-3360, D10): its terms to
 * their versions in that language, its slug kept while free there, else
 * the next; a published item's address takes the prefix, the old one kept
 * as history so it redirects, and its search words are rebuilt in the
 * language's rules. Refused when its group has a live item in that
 * language, when a term it is filed under has no version there, and for a
 * page with a parent or pages under it.
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
use App\Domain\Contents\ChangedLanguage;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\GroupLock;
use App\Domain\Contents\ItemTerms;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Contents\Operations\Input\ChangeContentLanguageInput;
use App\Domain\Contents\SearchText;
use App\Domain\Contents\Slugs;
use App\Domain\Contents\Tree\ContentTree;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Languages\LanguageChoice;
use App\Domain\Languages\LanguageLock;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Languages\TranslatedTypes;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class ChangeContentLanguage extends BaseOperation
{
    use PurgesTranslations;

    protected function authorize(OperationInput $input): void
    {
        /** @var ChangeContentLanguageInput $input */
        $actor = User::query()->find($input->actorId());
        $item = Content::withTrashed()->findOrFail($input->contentId);

        if ($actor === null || ! Gate::forUser($actor)->allows('update', $item)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ChangeContentLanguageInput $input */
        LanguageChoice::check($input->language);
        $item = Content::withTrashed()->findOrFail($input->contentId);
        TranslatedTypes::refuseType((string) $item->type);
        $members = $item->translation_group === null ? collect([$item]) : Content::query()->where('translation_group', $item->translation_group)->get();

        self::refuse($item, $members, $input->language);
        SitePages::refuseTakingOff((string) $item->id, 'language');
    }

    protected function perform(OperationInput $input): ChangedLanguage
    {
        /** @var ChangeContentLanguageInput $input */
        // D5's order: the languages, the tree, then the group's rows.
        LanguageLock::shared();
        $language = LanguageChoice::take($input->language);
        $type = (string) Content::withTrashed()->whereKey($input->contentId)->value('type');
        TranslatedTypes::refuseType($type, fromRow: true);
        // A type with one item only has one in each language (I-415): the types' lock, before the tree's.
        CreateDraft::refuseSecond($type, $language, 'language');
        app(SubtreeAddresses::class)->lock($type);
        $rows = GroupLock::lock([$input->contentId]);
        /** @var Content $item */
        $item = $rows->get($input->contentId);

        if ($item->working_revision_id !== $input->expectedRevisionId) {
            throw StaleRevisionException::for($input->expectedRevisionId, $item->working_revision_id);
        }

        $terms = self::refuse($item, $rows->reject(fn (Content $row): bool => $row->trashed()), $input->language, TranslatedTypes::sharedTaxonomies());
        SitePages::refuseTakingOff((string) $item->id, 'language');

        $before = ContentState::of($item);
        $shown = Footprints::content($item);
        $wasPublished = $item->status === 'published';
        $slug = (string) $item->slug;
        $free = Slugs::isAvailable($type, $slug, (string) $item->id, $language);

        // Revisions are never edited in place: each one in use is copied with its terms mapped.
        $termsBefore = (array) ContentRevision::query()->whereKey($item->working_revision_id)->value('terms');
        $same = $item->working_revision_id === $item->published_revision_id;
        $published = $item->published_revision_id === null ? null : self::mapped((string) $item->published_revision_id, $terms, (int) $input->actorId());
        $item->published_revision_id = $published;
        $item->working_revision_id = $same && $published !== null ? $published : self::mapped((string) $item->working_revision_id, $terms, (int) $input->actorId());
        $item->language = $language;
        $item->slug = $free ? $slug : Slugs::unique($type, $slug, $language);
        $item->save();
        ItemTerms::announce($item, $termsBefore, (array) ContentRevision::query()->whereKey($item->working_revision_id)->value('terms'), (string) $input->actorId());

        ContentCounts::apply($before, ContentState::of($item));
        TermProjection::sync($item);
        SearchText::sync($item);

        // Its new address must be free, as every Operation that moves one asks; a refusal rolls all of this back.
        $address = $wasPublished ? Addresses::forContent($item) : null;
        $clash = $address === null ? null : Paths::conflict($address, 'content', (string) $item->id);

        if ($clash !== null) {
            throw ValidationException::withMessages(['language' => $clash]);
        }

        app(PathProjection::class)->syncContent($item);

        $this->purgeAfterCommit(...self::translationKeys($rows, [$item->translation_group]));

        if ($wasPublished) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $item->id, [...$shown, ...Footprints::content($item)]));
        }

        $address = Addresses::forContent($item);

        return new ChangedLanguage(
            (string) $item->id,
            (string) $item->slug,
            $free ? null : $slug,
            app(SiteLanguages::class)->label($input->language),
            $address === null ? null : '/'.$address.'/',
        );
    }

    /**
     * Refuses what can't move, and answers each term the item is filed
     * under, on its working and published revisions, mapped to its version
     * in the language.
     *
     * @param  Collection<array-key, Content>  $members  the group's live members
     * @param  list<string>|null  $shared  the taxonomies that aren't translated, read from the row under the lock; the settings' when null
     *
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private static function refuse(Content $item, Collection $members, string $tag, ?array $shared = null): array
    {
        $languages = app(SiteLanguages::class);
        $language = $languages->stored($tag);
        $label = $languages->label($tag);

        if ($item->trashed()) {
            throw ValidationException::withMessages(['content' => __('Restore it first.')]);
        }

        if ($language === $item->language) {
            throw ValidationException::withMessages(['language' => __('It\'s already in :language.', ['language' => $label])]);
        }

        $type = app(ContentTypeRegistry::class)->get((string) $item->type);

        // The same-language rule of a page's tree (I-3373 (2)): counts a child in the trash too.
        if ($type->hierarchical && ($item->parent_id !== null || app(ContentTree::class)->hasChildren((string) $item->id))) {
            throw ValidationException::withMessages(['language' => __('Move it to the top, and move the pages under it, first.')]);
        }

        $taken = $members->first(fn (Content $member): bool => $member->id !== $item->id && $member->language === $language);

        if ($taken !== null) {
            throw ValidationException::withMessages(['language' => __('This :singular already has a :language version: “:title”.', ['singular' => mb_strtolower($type->singular), 'language' => $label, 'title' => (string) $taken->title])]);
        }

        $ids = ContentRevision::query()->whereIn('id', array_filter([$item->working_revision_id, $item->published_revision_id]))->pluck('terms')
            ->flatMap(fn (mixed $terms): array => array_values(array_filter((array) $terms, is_string(...))))->unique()->values()->all();

        return self::termVersions($ids, $language, $label, $shared ?? TranslatedTypes::untranslatedTaxonomies());
    }

    /**
     * Each term mapped to its version in the language; refused, naming it,
     * for one with none. A term gone since stays as it is: the projection drops it.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $shared  the taxonomies that aren't translated (D25)
     *
     * @return array<string, string>
     */
    private static function termVersions(array $ids, ?string $language, string $label, array $shared): array
    {
        if ($ids === []) {
            return [];
        }

        $terms = DB::table('terms')->whereIn('id', $ids)->orderBy('name')->orderBy('id')->get(['id', 'name', 'taxonomy', 'language', 'translation_group']);
        $versions = DB::table('terms')->whereIn('translation_group', $terms->pluck('translation_group')->filter()->all())
            ->when($language === null, fn ($query) => $query->whereNull('language'), fn ($query) => $query->where('language', $language))
            ->pluck('id', 'translation_group');
        $map = [];

        foreach ($terms as $term) {
            // A taxonomy that isn't translated shares its terms with every language (D25).
            $version = $term->language === $language || in_array((string) $term->taxonomy, $shared, true) ? $term->id : ($term->translation_group === null ? null : $versions[$term->translation_group] ?? null);

            if ($version === null) {
                $taxonomies = app(TaxonomyRegistry::class);
                $singular = $taxonomies->has((string) $term->taxonomy) ? mb_strtolower($taxonomies->get((string) $term->taxonomy)->singular) : (string) $term->taxonomy;

                throw ValidationException::withMessages(['terms' => __('Its :singular “:term” has no :language version. File it under a :language one first.', ['singular' => $singular, 'term' => (string) $term->name, 'language' => $label])]);
            }

            $map[(string) $term->id] = (string) $version;
        }

        return $map;
    }

    /**
     * A copy of the revision with its terms mapped, kind checkpoint; its ID.
     *
     * @param  array<string, string>  $terms
     */
    private static function mapped(string $revisionId, array $terms, int $actorId): string
    {
        $revision = ContentRevision::query()->findOrFail($revisionId);
        $copy = $revision->replicate(['id', 'created_at']);
        $copy->terms = array_values(array_unique(array_map(fn (string $id): string => $terms[$id] ?? $id, $revision->terms)));
        $copy->author_id = $actorId;
        $copy->kind = 'checkpoint';
        $copy->restored_from_id = null;
        $copy->save();

        return (string) $copy->id;
    }
}

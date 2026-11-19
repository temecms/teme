<?php

/**
 * Makes a draft of an item in another site language, in the item's
 * translation group (I-3360, D8): from its published revision when it has
 * one and is public, else from its working one, which only someone who may
 * edit it may copy. Its links point at the language's versions, its terms
 * at their siblings, the missing ones made; a page goes under its parent's
 * version in the language, or to the top. It credits whoever the item
 * credits (I-3366); the translator owns it. Nothing public changes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\GroupLock;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Slugs;
use App\Domain\Contents\TranslatedContent;
use App\Domain\Contents\TranslationCopy;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Contents\Tree\TreeRules;
use App\Domain\Contents\Visibility;
use App\Domain\Languages\LanguageChoice;
use App\Domain\Languages\LanguageLock;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Languages\TranslatedTypes;
use App\Domain\Languages\TranslationGroups;
use App\Domain\People\Operations\Concerns\GivesAccountsPeople;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class TranslateContent extends BaseOperation
{
    use GivesAccountsPeople;

    protected function authorize(OperationInput $input): void
    {
        /** @var TranslateContentInput $input */
        $actor = User::query()->find($input->actorId());
        $source = Content::withTrashed()->find($input->sourceId);

        if ($actor === null || $source === null || ! Gate::forUser($actor)->allows('create', [Content::class, $source->type])) {
            throw new AuthorizationException('Not permitted to create content.');
        }

        // A translator never reads text they could not open (D8, D23).
        if (self::copiesWorking($source) && ! Gate::forUser($actor)->allows('update', $source)) {
            if ($source->published_revision_id === null) {
                $languages = app(SiteLanguages::class);

                throw new AuthorizationException(__('The :language version isn\'t published yet. Ask someone who can edit it, or wait until it is.', ['language' => $languages->label($languages->of($source->language))]));
            }

            throw new AuthorizationException('Not permitted to translate this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var TranslateContentInput $input */
        LanguageChoice::check($input->language);
        $source = Content::withTrashed()->findOrFail($input->sourceId);

        if ($source->trashed()) {
            throw ValidationException::withMessages(['source' => __('Restore it first.')]);
        }

        TranslatedTypes::refuseType((string) $source->type);
        $languages = app(SiteLanguages::class);
        $language = $languages->stored($input->language);

        if ($language === $source->language) {
            throw ValidationException::withMessages(['language' => __('It\'s already in :language.', ['language' => $languages->label($input->language)])]);
        }

        $members = $source->translation_group === null ? collect([$source]) : Content::query()->where('translation_group', $source->translation_group)->get();
        self::refuseTaken($members, $source, $language, $input->language);
    }

    protected function perform(OperationInput $input): TranslatedContent
    {
        /** @var TranslateContentInput $input */
        // D5's order: the languages, the tree, then the group's rows.
        LanguageLock::shared();
        $language = LanguageChoice::take($input->language);
        $type = (string) Content::withTrashed()->whereKey($input->sourceId)->value('type');
        TranslatedTypes::refuseType($type, fromRow: true);
        // A type with one item only has one in each language (I-415): the types' lock, before the tree's.
        CreateDraft::refuseSecond($type, $language, 'language');
        $nests = app(SubtreeAddresses::class)->lock($type);
        // The account a credit by inference names, before any row, so deleting it waits or is waited for (I-3366).
        $owner = self::inferredOwner($input->sourceId);

        if ($owner !== null) {
            DB::select('SELECT id FROM users WHERE id = ? FOR KEY SHARE', [$owner]);
        }

        $rows = GroupLock::lock([$input->sourceId]);
        /** @var Content $source */
        $source = $rows->get($input->sourceId);

        if (self::inferredOwner($input->sourceId) !== $owner) {
            throw ValidationException::withMessages(['source' => __('The item changed while it was being translated. Try again.')]);
        }

        if ($source->trashed()) {
            throw ValidationException::withMessages(['source' => __('Restore it first.')]);
        }

        self::refuseTaken($rows->filter(fn (Content $row): bool => ! $row->trashed()), $source, $language, $input->language);

        $group = $source->translation_group;

        if ($group === null) {
            $group = TranslationGroups::newId();
            DB::table('contents')->where('id', $source->id)->update(['translation_group' => $group]);
        }

        $revision = ContentRevision::query()->findOrFail(self::copiesWorking($source) ? $source->working_revision_id : $source->published_revision_id);
        $copy = TranslationCopy::of($revision, $type, $language);
        $slug = Slugs::unique($type, (string) $source->slug, $language);

        try {
            // In a savepoint of its own, so the refusal below can still read the version that won.
            $content = DB::transaction(fn (): Content => CreateDraft::start(
                $type,
                $slug,
                $copy['title'],
                $copy['document'],
                $copy['fields'],
                $copy['terms'],
                (string) $input->actorId(),
                $language,
                $group,
                (string) $revision->id,
            ));
        } catch (QueryException $failure) {
            // A version committed in the language between the check and the write.
            if ($failure->getCode() === '23505' && str_contains($failure->getMessage(), 'contents_translation_group_language_unique')) {
                throw self::taken($source, (string) Content::query()->where('translation_group', $group)->where('language', $language)->value('title'), $input->language);
            }

            throw $failure;
        }

        $this->copyCredits((string) $source->id, (string) $content->id, $owner);
        $this->purgeAfterCommit(...$copy['purge']);

        [$parentLeft, $parentTitle] = $nests && $source->parent_id !== null ? self::place($content, (string) $source->parent_id, $language) : [false, null];

        return new TranslatedContent($content->refresh(), $copy['made'], $parentLeft, $parentTitle, app(SiteLanguages::class)->label($input->language));
    }

    /**
     * The owner the source credits by inference: its owner while it has no
     * bylines; null when it has bylines or no owner.
     */
    private static function inferredOwner(string $sourceId): ?int
    {
        $owner = DB::table('contents as c')->where('c.id', $sourceId)
            ->whereNotExists(fn ($bylines) => $bylines->from('content_bylines as b')->whereColumn('b.content_id', 'c.id'))
            ->value('c.author_id');

        return $owner === null ? null : (int) $owner;
    }

    /**
     * Gives the translation the source's credits as bylines, in order, after
     * its own row, people last: the source's own bylines, or its owner's
     * person when it credits by inference, made now if none.
     */
    private function copyCredits(string $sourceId, string $translationId, ?int $owner): void
    {
        if ($owner !== null) {
            DB::table('content_bylines')->insert(['content_id' => $translationId, 'person_id' => $this->personIdsFor([$owner])[$owner], 'position' => 0]);

            return;
        }

        DB::insert('INSERT INTO content_bylines (content_id, person_id, position) SELECT ?, person_id, position FROM content_bylines WHERE content_id = ? ORDER BY position', [$translationId, $sourceId]);
    }

    /**
     * Whether the copy is of the working revision: the source has no
     * published one, or isn't public (D8).
     */
    /**
     * Whether the person may translate the item into a language it lacks
     * (D23): create its type, and, when its unpublished text would be
     * copied, edit it. The same for every missing language, so the editor
     * asks once.
     */
    public static function allows(User $actor, Content $source): bool
    {
        $gate = Gate::forUser($actor);

        return $gate->allows('create', [Content::class, $source->type]) && (! self::copiesWorking($source) || $gate->allows('update', $source));
    }

    private static function copiesWorking(Content $source): bool
    {
        return $source->published_revision_id === null || $source->visibility !== Visibility::Public;
    }

    /**
     * Refuses when a live member of the group is in the language, naming it.
     *
     * @param  Collection<array-key, Content>  $members
     *
     * @throws ValidationException
     */
    private static function refuseTaken(Collection $members, Content $source, ?string $language, string $tag): void
    {
        $taken = $members->first(fn (Content $member): bool => $member->language === $language);

        if ($taken !== null) {
            throw self::taken($source, (string) $taken->title, $tag);
        }
    }

    private static function taken(Content $source, string $title, string $tag): ValidationException
    {
        return ValidationException::withMessages(['language' => __('This :singular already has a :language version: “:title”.', [
            'singular' => mb_strtolower(app(ContentTypeRegistry::class)->get((string) $source->type)->singular),
            'language' => app(SiteLanguages::class)->label($tag),
            'title' => $title,
        ])]);
    }

    /**
     * Puts a page's new version under its parent's version in the language,
     * live or not, last among its siblings; or leaves it at the top when
     * there is none, answering that it did, with the parent's title. A draft
     * has no address, so nothing moves (as PlaceImportedPage writes it).
     *
     * @return array{0: bool, 1: string|null}
     */
    private static function place(Content $content, string $sourceParentId, ?string $language): array
    {
        $parent = Content::query()->find($sourceParentId, ['id', 'title', 'translation_group']);
        $version = $parent?->translation_group === null ? null : Content::query()
            ->where('translation_group', $parent->translation_group)
            ->when($language === null, fn ($query) => $query->whereNull('language'), fn ($query) => $query->where('language', $language))
            ->value('id');

        if ($version === null || app(TreeRules::class)->refusal($content, (string) $version) !== null) {
            return [true, $parent === null ? null : (string) $parent->title];
        }

        $largest = Content::query()->where('type', $content->type)->where('id', '<>', $content->id)->where('parent_id', $version)->max('position');
        DB::table('contents')->where('id', $content->id)->update(['parent_id' => $version, 'position' => $largest === null ? 0 : (int) $largest + 1]);

        return [false, null];
    }
}

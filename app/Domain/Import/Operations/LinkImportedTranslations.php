<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Contents\GroupLock;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Concerns\MovesVersionAddresses;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\LinkImportedTranslationsInput;
use App\Domain\Languages\LanguageLock;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Languages\TranslationGroups;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\Models\Term;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Links one batch of an imported site's translations (I-351), as starting
 * an import allows: each group of WordPress IDs becomes one translation
 * group of the items, or the terms, the import made of them, joining any
 * group they are in already. A group whose items aren't of one type, or one
 * translated here, or that would hold two in one language, is skipped and
 * answered with why, never half-linked. A version that becomes another
 * language's home page moves to that language's home, its old address
 * kept as history.
 */
final class LinkImportedTranslations extends BaseOperation
{
    use AuthorizesImports;
    use MovesVersionAddresses;
    use PurgesTranslations;

    /** The most groups one batch links. */
    public const int BATCH = 500;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var LinkImportedTranslationsInput $input */
        if (! in_array($input->kind, ['content', 'term'], true)) {
            throw ValidationException::withMessages(['kind' => 'Link posts and pages, or terms.']);
        }

        if (count($input->groups) > self::BATCH) {
            throw ValidationException::withMessages(['groups' => 'At most 500 groups at a time.']);
        }
    }

    /**
     * @return array{linked: int, skipped: list<array{ids: list<string>, reason: string}>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var LinkImportedTranslationsInput $input */
        LanguageLock::shared();
        $members = [];

        foreach ($input->groups as $ids) {
            $members[] = $input->kind === 'content' ? $this->items($input->sourceId, $ids) : $this->terms($input->sourceId, $ids);
        }

        if ($input->kind === 'content') {
            // Every tree first, in one order, before any row (I-3360).
            $this->takeTrees(array_merge([], ...$members));
        }

        $linked = 0;
        $skipped = [];

        foreach ($input->groups as $index => $ids) {
            if (count($members[$index]) < 2) {
                continue;
            }

            try {
                $changed = DB::transaction(fn (): bool => $input->kind === 'content' ? $this->linkItems($members[$index]) : $this->linkTerms($members[$index]));
            } catch (ValidationException $refused) {
                $skipped[] = ['ids' => $ids, 'reason' => (string) (Arr::first(Arr::flatten($refused->errors())) ?? '')];

                continue;
            }

            $linked += $changed ? 1 : 0;
        }

        return ['linked' => $linked, 'skipped' => $skipped];
    }

    /**
     * The live items the import made of these WordPress posts, by ID.
     *
     * @param  list<string>  $ids
     *
     * @return list<string>
     */
    private function items(string $sourceId, array $ids): array
    {
        return DB::table('import_map')
            ->join('contents', 'contents.id', '=', 'import_map.teme_id')
            ->where('import_map.source_id', $sourceId)
            ->where('import_map.object_type', 'post')
            ->whereIn('import_map.object_id', $ids)
            ->where('import_map.teme_type', 'content')
            ->whereNull('contents.deleted_at')
            ->orderBy('contents.id')
            ->pluck('contents.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * The terms the import made of these WordPress terms, by term ID.
     *
     * @param  list<string>  $ids
     *
     * @return list<string>
     */
    private function terms(string $sourceId, array $ids): array
    {
        return DB::table('import_map')
            ->join('terms', 'terms.id', '=', 'import_map.teme_id')
            ->where('import_map.source_id', $sourceId)
            ->where('import_map.object_type', 'term')
            ->where('import_map.teme_type', 'term')
            ->whereIn(DB::raw("import_map.facts ->> 'term_id'"), $ids)
            ->orderBy('terms.id')
            ->pluck('terms.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * Joins the items, and every member of their groups, into one group;
     * whether anything changed.
     *
     * @param  list<string>  $ids
     *
     * @throws ValidationException
     */
    private function linkItems(array $ids): bool
    {
        $rows = GroupLock::lock($ids);
        $named = $rows->only($ids);
        self::refuse($named, $rows, 'type', fn (string $type): bool => app(SiteLanguages::class)->translates($type));

        $before = $rows->pluck('translation_group')->filter()->unique()->values()->all();
        $group = $named->pluck('translation_group')->filter()->first() ?? TranslationGroups::newId();

        if ($rows->every(fn (Content $item): bool => $item->translation_group === $group)) {
            return false;
        }

        DB::table('contents')->whereIn('id', $rows->keys()->all())->update(['translation_group' => $group]);
        $this->moveVersionAddresses($rows, 'translations');
        $this->purgeAfterCommit(...self::translationKeys($rows, [...$before, $group]));

        return true;
    }

    /**
     * Joins the terms, and every member of their groups, into one group;
     * whether anything changed.
     *
     * @param  list<string>  $ids
     *
     * @throws ValidationException
     */
    private function linkTerms(array $ids): bool
    {
        $groups = DB::table('terms')->whereIn('id', $ids)->whereNotNull('translation_group')->distinct()->pluck('translation_group')->all();

        /** @var Collection<string, Term> $rows */
        $rows = Term::query()
            ->where(fn ($query) => $query->whereIn('id', $ids)->when($groups !== [], fn ($query) => $query->orWhereIn('translation_group', $groups)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $named = $rows->only($ids);
        self::refuse($named, $rows, 'taxonomy', fn (string $taxonomy): bool => app(SiteLanguages::class)->translatesTaxonomy($taxonomy));

        $before = $rows->pluck('translation_group')->filter()->unique()->values()->all();
        $group = $named->pluck('translation_group')->filter()->first() ?? TranslationGroups::newId();

        if ($rows->every(fn (Term $term): bool => $term->translation_group === $group)) {
            return false;
        }

        DB::table('terms')->whereIn('id', $rows->keys()->all())->update(['translation_group' => $group]);
        $tags = array_map(PageTags::translations(...), array_values(array_unique([...array_map(strval(...), $before), $group])));

        foreach ($rows as $term) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::TERM, (string) $term->id, [...$tags, PageTags::term((string) $term->id)]));
        }

        return true;
    }

    /**
     * Refuses members of more than one type or taxonomy, one not translated
     * here, and two live members of the joined groups in one language.
     *
     * @param  Collection<string, Content>|Collection<string, Term>  $named
     * @param  Collection<string, Content>|Collection<string, Term>  $members
     * @param  callable(string): bool  $translated
     *
     * @throws ValidationException
     */
    private static function refuse(Collection $named, Collection $members, string $kind, callable $translated): void
    {
        $kinds = $named->pluck($kind)->unique()->values();

        if ($kinds->count() > 1) {
            throw ValidationException::withMessages(['translations' => __('They are of different types on this site: :types.', ['types' => $kinds->implode(', ')])]);
        }

        if (! $translated((string) $kinds->first())) {
            throw ValidationException::withMessages(['translations' => __(':kind isn’t translated on this site.', ['kind' => (string) $kinds->first()])]);
        }

        $shared = $members->reject(fn (Content|Term $row): bool => $row instanceof Content && $row->trashed())
            ->groupBy(fn (Content|Term $row): string => (string) $row->language)
            ->first(fn (Collection $same): bool => $same->count() > 1);

        if ($shared !== null) {
            $languages = app(SiteLanguages::class);

            throw ValidationException::withMessages(['translations' => __('Two of them are in :language. Each language can have one version.', ['language' => $languages->label($languages->of($shared->first()?->language))])]);
        }
    }
}

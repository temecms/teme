<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy\Operations;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Languages\LanguageLock;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\Concerns\ValidatesTerms;
use App\Domain\Taxonomy\Operations\Input\ImportTermInput;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Brings one WordPress category or tag into Teme for an import: creates it
 * at the address it had, or brings the one an earlier run made up to date,
 * its old address kept as a redirect (I-061). The slug is the caller's,
 * never changed here; only an importer may.
 */
final class ImportTerm extends BaseOperation
{
    use AuthorizesImports;
    use ValidatesTerms;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportTermInput $input */
        if (trim($input->slug) === '') {
            throw ValidationException::withMessages(['slug' => __('The term needs an address.')]);
        }

        if ($input->termId !== null) {
            $taxonomy = Term::query()->whereKey($input->termId)->value('taxonomy');

            if ($taxonomy === null) {
                throw ValidationException::withMessages(['term' => __('The term to update no longer exists.')]);
            }

            if ($taxonomy !== $input->taxonomy) {
                throw ValidationException::withMessages(['taxonomy' => __('A term cannot move to another taxonomy.')]);
            }
        }

        $this->validateTerm($input->taxonomy, $input->name, $input->slug, $input->parentId, $input->termId);

        // A new term's language is the input's; one made before keeps its own (I-3446).
        $language = $input->termId === null ? $input->language : Term::query()->whereKey($input->termId)->value('language');

        $taken = Term::query()
            ->where('taxonomy', $input->taxonomy)
            ->where('slug', $input->slug)
            ->where(fn ($query) => $language === null ? $query->whereNull('language') : $query->where('language', $language))
            ->when($input->termId !== null, fn ($query) => $query->whereKeyNot($input->termId))
            ->exists();

        if ($taken) {
            $singular = mb_strtolower(app(TaxonomyRegistry::class)->get($input->taxonomy)->singular);

            throw ValidationException::withMessages(['slug' => __('Another :singular already has this address.', ['singular' => $singular])]);
        }
    }

    /**
     * @return array{termId: string, created: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportTermInput $input */
        $fields = ['name' => trim($input->name), 'slug' => $input->slug, 'parent_id' => $input->parentId, 'description' => $input->description];

        if ($input->termId === null) {
            // A term given a language takes the languages' lock first (I-3360).
            if ($input->language !== null) {
                LanguageLock::shared();
            }

            // refresh(): the columns the database defaults aren't on the model after an insert.
            $term = Term::query()->create(['taxonomy' => $input->taxonomy, 'language' => $input->language, ...$fields])->refresh();
            app(PathProjection::class)->syncTerm($term);

            // A "not found" page may be stored at the address it takes (I-263).
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::TERM, (string) $term->id, Footprints::terms([(string) $term->id])[(string) $term->id] ?? []));

            return ['termId' => (string) $term->id, 'created' => true];
        }

        $term = Term::query()->lockForUpdate()->findOrFail($input->termId);
        // It and every term beneath it, whose addresses hold its slug (I-263).
        $shown = Footprints::termAndBeneath((string) $term->id);
        $term->fill($fields);
        $moved = $term->isDirty(['slug', 'parent_id']);
        $term->save();

        // Its descendants' addresses hold its slug too.
        app(PathProjection::class)->syncTerm($term);

        $after = Footprints::terms(array_map(strval(...), array_keys($shown)));

        foreach ($shown as $id => $before) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::TERM, (string) $id, [...$before, ...($after[(string) $id] ?? [])]));
        }

        // So do the items filed under it, where an address takes {category} from its taxonomy.
        if ($moved && AddressSettings::current()->termsMoveItems((string) $term->taxonomy)) {
            RecomputeAddresses::dispatch(JobActor::of($input->actorId()), (string) $term->id)->afterCommit();
        }

        return ['termId' => (string) $term->id, 'created' => false];
    }
}

<?php

/**
 * Changes one of the site's own taxonomies and which types use it: names,
 * admin address, icon, how its terms are arranged and whether they have
 * public pages, under the rules its terms and items set (I-412). Its address
 * base stays the one it was made with; Settings › Addresses changes that.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Exceptions\InvalidTaxonomyDefinition;
use App\Domain\Types\Exceptions\InvalidTypeDefinition;
use App\Domain\Types\Operations\Concerns\ManagesTaxonomies;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\Operations\Input\UpdateTaxonomyInput;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TaxonomyRules;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class UpdateTaxonomy extends BaseOperation
{
    use ManagesTaxonomies;
    use ManagesTypes;

    public function __construct(private readonly StoredTaxonomies $taxonomies, private readonly StoredTypes $stored) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeTypes($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateTaxonomyInput $input */
        // A row nobody can read opens on the taxonomies screen to be fixed:
        // it is judged as far as it can be read, and the change replaces it.
        try {
            $current = $this->taxonomies->find($input->taxonomy->key);
        } catch (InvalidTaxonomyDefinition) {
            $current = $this->taxonomies->findLeniently($input->taxonomy->key);
        }

        if ($current === null) {
            $registry = app(TaxonomyRegistry::class);

            if ($registry->has($input->taxonomy->key) && ! $registry->get($input->taxonomy->key)->stored) {
                throw ValidationException::withMessages([
                    'key' => __(':plural are declared by code, so they can’t be changed or deleted here.', [
                        'plural' => $registry->get($input->taxonomy->key)->plural,
                    ]),
                ]);
            }

            throw ValidationException::withMessages([
                'key' => __('There is no taxonomy :key.', ['key' => $input->taxonomy->key]),
            ]);
        }

        $problems = TaxonomyRules::problems($input->taxonomy, $current, $input->types);

        foreach ($input->types as $key) {
            if (! $this->stored->exists($key)) {
                continue;
            }

            try {
                $this->stored->find($key);
            } catch (InvalidTypeDefinition) {
                $problems['types'] = __(':key can’t be read, so whether it uses :Plural can’t change here. Fix it on Site › Types first.', ['key' => $key, 'Plural' => $current->plural]);
            }
        }

        $this->refuse($problems);
    }

    /**
     * @return array{key: string, changed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var UpdateTaxonomyInput $input */
        StoredTypes::lock();

        try {
            $current = $this->taxonomies->find($input->taxonomy->key, forUpdate: true);
            $mending = false;
        } catch (InvalidTaxonomyDefinition) {
            $current = $this->taxonomies->findLeniently($input->taxonomy->key, forUpdate: true);
            $mending = true;
        }

        if ($current === null) {
            throw ValidationException::withMessages(['key' => __('There is no taxonomy :key.', ['key' => $input->taxonomy->key])]);
        }

        $types = app(ContentTypeRegistry::class);
        $code = array_values(array_filter($input->types, fn (string $key): bool => $types->has($key) && ! $types->get($key)->stored));
        $stored = array_values(array_diff($input->types, $code));
        // A stored type the registry refuses isn't offered: what it names stays.
        $storedBefore = array_values(array_filter($this->storedTypesNaming($current->key), fn (string $key): bool => $types->has($key)));

        // A taxonomy keeps the base it was made with; Settings › Addresses
        // changes it, and keeps every old address (I-3300). One becoming
        // public has none to keep: it takes the base validate() checked.
        $becomesPublic = $input->taxonomy->public && ! $current->public;
        $next = ($becomesPublic ? $input->taxonomy : $input->taxonomy->withBase($current->base))->withCodeTypes($code)->settled();
        $takenOff = [...array_diff($current->codeTypes, $code), ...array_diff($storedBefore, $stored)];

        $this->refuse([
            ...$this->taxonomyTakenProblems($next, new: false, newBase: $becomesPublic),
            ...$this->termProblems($next, $current),
            ...$this->takenOffProblems($current, $takenOff),
        ]);

        $changed = $mending || $next->toArray() !== $current->toArray();

        if ($changed) {
            $this->taxonomies->update($next);
        }

        // Which stored types use it lives on each type: their own Operation
        // writes them, in this transaction (I-3319).
        foreach ([...array_diff($stored, $storedBefore), ...array_diff($storedBefore, $stored)] as $typeKey) {
            $type = $this->stored->find($typeKey, forUpdate: true);

            if ($type === null) {
                continue;
            }

            $taxonomies = in_array($next->key, $type->taxonomies, true)
                ? array_values(array_diff($type->taxonomies, [$next->key]))
                : [...$type->taxonomies, $next->key];

            app(UpdateType::class)->execute(new UpdateTypeInput($type->withTaxonomies($taxonomies), $input->actorId));
            $changed = true;
        }

        if ($changed) {
            $this->purgeEverything();
        }

        return ['key' => $next->key, 'changed' => $changed];
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->typesChanged();
    }
}

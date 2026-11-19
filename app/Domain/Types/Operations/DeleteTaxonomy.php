<?php

/**
 * Removes one of the site's own taxonomies when it has no terms (I-3319),
 * taking it off every type that used it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\Exceptions\InvalidTypeDefinition;
use App\Domain\Types\Operations\Concerns\ManagesTaxonomies;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\Operations\Input\DeleteTaxonomyInput;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class DeleteTaxonomy extends BaseOperation
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
        /** @var DeleteTaxonomyInput $input */
        if ($this->taxonomies->exists($input->key)) {
            return;
        }

        $registry = app(TaxonomyRegistry::class);

        if ($registry->has($input->key) && ! $registry->get($input->key)->stored) {
            throw ValidationException::withMessages([
                'key' => __(':plural are declared by code, so they can’t be changed or deleted here.', [
                    'plural' => $registry->get($input->key)->plural,
                ]),
            ]);
        }

        throw ValidationException::withMessages([
            'key' => __('There is no taxonomy :key.', ['key' => $input->key]),
        ]);
    }

    /**
     * @return array{key: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var DeleteTaxonomyInput $input */
        StoredTypes::lock();

        $plural = $this->taxonomies->rawPlural($input->key);

        if ($plural === null) {
            throw ValidationException::withMessages(['key' => __('There is no taxonomy :key.', ['key' => $input->key])]);
        }

        $count = DB::table('terms')->where('taxonomy', $input->key)->count();

        if ($count > 0) {
            throw ValidationException::withMessages(['key' => $count === 1
                ? __(':Plural have 1 term, so the taxonomy can’t be deleted. Delete it first.', ['Plural' => $plural])
                : __(':Plural have :count terms, so the taxonomy can’t be deleted. Delete them first.', ['Plural' => $plural, 'count' => (string) $count]),
            ]);
        }

        // Which types use it lives on each type: their own Operation takes
        // it off, in this transaction (I-3319). A row nobody can read stays
        // as it is: it is mended on Site › Types.
        foreach ($this->storedTypesNaming($input->key) as $typeKey) {
            try {
                $type = $this->stored->find($typeKey, forUpdate: true);
            } catch (InvalidTypeDefinition) {
                continue;
            }

            if ($type === null) {
                continue;
            }

            app(UpdateType::class)->execute(new UpdateTypeInput($type->withTaxonomies(array_values(array_diff($type->taxonomies, [$input->key]))), $input->actorId));
        }

        $this->taxonomies->delete($input->key);
        $this->purgeEverything();

        return ['key' => $input->key];
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->typesChanged();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Types\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Exceptions\InvalidTypeDefinition;
use App\Domain\Types\Operations\Concerns\ManagesTaxonomies;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\Operations\Input\CreateTaxonomyInput;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TaxonomyRules;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Makes one of the site's own taxonomies and adds it to the types that use
 * it, live in every process at once (I-3296). Its address base is set now
 * and never follows a later change here.
 */
final class CreateTaxonomy extends BaseOperation
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
        /** @var CreateTaxonomyInput $input */
        $problems = TaxonomyRules::problems($input->taxonomy, null, $input->types);

        foreach ($input->types as $key) {
            if (! $this->stored->exists($key)) {
                continue;
            }

            try {
                $this->stored->find($key);
            } catch (InvalidTypeDefinition) {
                $problems['types'] ??= __(':key can’t be read, so whether it uses :Plural can’t change here. Fix it on Site › Types first.', ['key' => $key, 'Plural' => $input->taxonomy->plural]);
            }
        }

        $this->refuse($problems);
    }

    /**
     * @return array{key: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var CreateTaxonomyInput $input */
        StoredTypes::lock();

        $types = app(ContentTypeRegistry::class);
        $code = array_values(array_filter($input->types, fn (string $key): bool => $types->has($key) && ! $types->get($key)->stored));
        $stored = array_values(array_diff($input->types, $code));
        $taxonomy = $input->taxonomy->withCodeTypes($code)->settled();

        $this->refuse($this->taxonomyTakenProblems($taxonomy, new: true));
        $this->taxonomies->insert($taxonomy);

        // Which stored types use it lives on each type: their own Operation
        // writes them, in this transaction (I-3319).
        foreach ($stored as $typeKey) {
            $type = $this->stored->find($typeKey, forUpdate: true);

            if ($type === null) {
                throw ValidationException::withMessages(['types' => __('There is no type :type.', ['type' => $typeKey])]);
            }

            app(UpdateType::class)->execute(new UpdateTypeInput($type->withTaxonomies([...$type->taxonomies, $taxonomy->key]), $input->actorId));
        }

        $this->purgeEverything();

        return ['key' => $taxonomy->key];
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->typesChanged();
    }
}

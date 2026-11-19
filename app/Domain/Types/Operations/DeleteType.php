<?php

/**
 * Removes one of the site's own content types when it has no items, trashed
 * ones included (I-411). Its screens, settings, fields and own permissions go with it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations;

use App\Domain\Fields\Operations\Concerns\ManagesFields;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\Operations\Input\DeleteTypeInput;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TypeDefinition;
use App\Domain\Types\TypePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class DeleteType extends BaseOperation
{
    use ManagesFields;
    use ManagesTypes;

    public function __construct(private readonly StoredTypes $stored) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeTypes($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var DeleteTypeInput $input */
        $singular = $this->stored->rawSingular($input->key);

        if ($singular !== null) {
            return;
        }

        $types = app(ContentTypeRegistry::class);

        if ($types->has($input->key) && ! $types->get($input->key)->stored) {
            throw ValidationException::withMessages([
                'key' => __(':plural are declared by code, so they can’t be changed or deleted here.', [
                    'plural' => $types->get($input->key)->plural,
                ]),
            ]);
        }

        throw ValidationException::withMessages([
            'key' => __('There is no type :key.', ['key' => $input->key]),
        ]);
    }

    /**
     * @return array{key: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var DeleteTypeInput $input */
        StoredTypes::lock();

        $singular = $this->stored->rawSingular($input->key);

        if ($singular === null) {
            throw ValidationException::withMessages(['key' => __('There is no type :key.', ['key' => $input->key])]);
        }

        // DB::table() sees soft-deleted rows: trashed items count (I-412).
        $count = DB::table('contents')->where('type', $input->key)->count();

        if ($count > 0) {
            throw ValidationException::withMessages(['key' => __('The type :singular has items (:count, trashed ones included), so it can’t be deleted.', ['singular' => $singular, 'count' => (string) $count])]);
        }

        $row = $this->stored->findLeniently($input->key, forUpdate: true);
        $this->stored->delete($input->key);
        // It has no items, so its fields hold no values to lose.
        app(StoredFields::class)->deleteForType($input->key);

        if ($row?->permissions === TypeDefinition::OWN) {
            TypePermissions::remove($input->key);
        }
        $this->purgeEverything();

        return ['key' => $input->key];
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->typesChanged();
        $this->fieldsChanged();
    }
}

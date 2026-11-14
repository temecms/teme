<?php

declare(strict_types=1);

namespace App\Domain\Types\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Exceptions\InvalidTypeDefinition;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TypeRules;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Changes one of the site's own content types, and its own permissions,
 * when it has them: names, admin address, icon, features and taxonomies,
 * and mends a saved row nobody can read (I-411). Its public address pattern
 * stays the one it was made with; Settings › Addresses changes that.
 */
final class UpdateType extends BaseOperation
{
    use ManagesTypes;

    public function __construct(private readonly StoredTypes $stored) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeTypes($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateTypeInput $input */
        // A row nobody can read opens on the types screen to be fixed (I-3302):
        // it is judged as far as it can be read, and the change replaces it.
        try {
            $current = $this->stored->find($input->type->key);
            $mending = false;
        } catch (InvalidTypeDefinition) {
            $current = $this->stored->findLeniently($input->type->key);
            $mending = true;
        }

        if ($current === null) {
            $types = app(ContentTypeRegistry::class);

            if ($types->has($input->type->key) && ! $types->get($input->type->key)->stored) {
                throw ValidationException::withMessages([
                    'key' => __(':plural are declared by code, so they can’t be changed or deleted here.', [
                        'plural' => $types->get($input->type->key)->plural,
                    ]),
                ]);
            }

            throw ValidationException::withMessages([
                'key' => __('There is no type :key.', ['key' => $input->type->key]),
            ]);
        }

        // A mended row keeps the pattern it could read, as perform() writes it,
        // so that is the pattern the rules judge.
        $this->refuse(TypeRules::problems($mending ? $input->type->withPattern($current->pattern) : $input->type, $current));
    }

    /**
     * @return array{key: string, changed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var UpdateTypeInput $input */
        StoredTypes::lock();

        try {
            $current = $this->stored->find($input->type->key, forUpdate: true);
            $mending = false;
        } catch (InvalidTypeDefinition) {
            $current = $this->stored->findLeniently($input->type->key, forUpdate: true);
            $mending = true;
        }

        if ($current === null) {
            throw ValidationException::withMessages(['key' => __('There is no type :key.', ['key' => $input->type->key])]);
        }

        // A public type keeps the pattern it was made with; it changes in
        // Settings › Addresses, which keeps every old address (I-412). A type
        // becoming public takes the pattern validate() checked.
        $next = $current->pattern === null
            ? $input->type->settled()
            : $input->type->withPattern($current->pattern)->settled();
        $this->refuse([...$this->itemProblems($next, $current), ...$this->takenProblems($next, new: false), ...$this->permissionProblems($next, $current)]);

        // A mended row is written even when what could be read is unchanged:
        // the row as stored is what couldn't be read.
        if (! $mending && $next->toArray() === $current->toArray()) {
            return ['key' => $next->key, 'changed' => false];
        }

        $this->stored->update($next);
        $this->applyPermissions($next, $current);
        $this->purgeEverything();

        return ['key' => $next->key, 'changed' => true];
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->typesChanged();
    }
}

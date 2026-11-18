<?php

/**
 * Makes one of the site's own content types, and its own permissions, when
 * it has them, live in every process at once (I-3296). A public type's
 * address pattern is set now, from its segment unless one is given, and
 * never follows the segment later.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\Operations\Input\CreateTypeInput;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TypeRules;
use Teme\Contracts\Operations\OperationInput;

final class CreateType extends BaseOperation
{
    use ManagesTypes;

    public function __construct(private readonly StoredTypes $stored) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeTypes($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CreateTypeInput $input */
        $this->refuse(TypeRules::problems($input->type, null));
    }

    /**
     * @return array{key: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var CreateTypeInput $input */
        StoredTypes::lock();

        $type = $input->type->settled();
        $this->refuse([...$this->takenProblems($type, new: true), ...$this->permissionProblems($type, null)]);
        $this->stored->insert($type);
        $this->applyPermissions($type, null);
        $this->purgeEverything();

        return ['key' => $type->key];
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->typesChanged();
    }
}

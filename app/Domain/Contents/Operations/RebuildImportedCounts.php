<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Operations\Concerns\RecountsContent;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;

/**
 * The counts checked once at an import's end and fixed where they drifted
 * (I-141), as teme:counts:rebuild does, by the import's actor. Only an
 * import's actor may call it.
 */
final class RebuildImportedCounts extends BaseOperation
{
    use AuthorizesImports;
    use RecountsContent;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void {}

    /**
     * @return array{drifted: int, fixed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        return $this->recount(true);
    }
}

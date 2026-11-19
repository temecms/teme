<?php

/**
 * Adds and updates the site's own content types from a teme-types.json file:
 * the taxonomies before the types that name them, then the types' fields. It
 * never removes a type, taxonomy or field that is only on this site (I-411),
 * and refuses to change one a plugin brought, which only that plugin changes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations;

use App\Domain\Fields\Operations\Concerns\ManagesFields;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\Operations\Concerns\AppliesTypesFile;
use App\Domain\Types\Operations\Concerns\ManagesTaxonomies;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class ImportTypes extends BaseOperation
{
    use AppliesTypesFile;
    use ManagesFields;
    use ManagesTaxonomies;
    use ManagesTypes;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeTypes($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportTypesInput $input */
        $problems = $this->typesFileProblems($input->file);

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * Fields are named type.handle in the report.
     *
     * @return array{added: list<string>, updated: list<string>, unchanged: list<string>, onlyHere: list<string>, patternKept: list<string>, grantsKept: list<string>, taxonomies: array{added: list<string>, updated: list<string>, unchanged: list<string>, onlyHere: list<string>, baseKept: list<string>}, fieldsAdded: list<string>, fieldsUpdated: list<string>, fieldsUnchanged: list<string>, fieldsOnlyHere: list<string>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportTypesInput $input */
        return $this->applyTypesFile($input->file, $input->actorId());
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->typesChanged();
        $this->fieldsChanged();
    }
}

<?php

/**
 * Moves a term's map row, made when the site was imported from its export
 * file, to the ID the companion gives the same term, so a live import after
 * a file's updates the term rather than making it twice.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\LocksObjects;
use App\Domain\Import\Operations\Input\AdoptImportedTermInput;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Support\Operations\BaseOperation;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * An export file numbers a term by its term ID, the companion by its
 * taxonomy row's ID, so one term can have two numbers (docs/architecture/import.md).
 * The row keeps the file's number in its facts, where the next file's read finds it.
 */
final class AdoptImportedTerm extends BaseOperation
{
    use AuthorizesImports;
    use LocksObjects;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AdoptImportedTermInput $input */
        if ($input->fromId === $input->toId || preg_match('/^-?\d{1,18}$/', $input->toId) !== 1 || preg_match('/^-?\d{1,18}$/', $input->fromId) !== 1) {
            throw new InvalidArgumentException('Adopting a term’s map row needs two different WordPress IDs.');
        }
    }

    /**
     * @return bool whether the row moved: false when it had gone, or the new ID already has one
     */
    protected function perform(OperationInput $input): bool
    {
        /** @var AdoptImportedTermInput $input */
        $to = SourceRef::of('term', $input->toId);
        $this->lockObject($input->sourceId, SourceRef::of('term', $input->fromId));
        $this->lockObject($input->sourceId, $to);
        $rows = ImportMapEntry::query()->where('source_id', $input->sourceId)->where('object_type', 'term');

        if ((clone $rows)->where('object_id', $input->toId)->exists()) {
            return false;
        }

        $row = (clone $rows)->where('object_id', $input->fromId)->lockForUpdate()->first();

        if ($row === null || ! array_key_exists('file_term_id', $row->facts)) {
            return false;
        }

        $row->forceFill(['object_id' => $input->toId])->save();

        return true;
    }
}

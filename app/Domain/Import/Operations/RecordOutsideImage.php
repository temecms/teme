<?php

/**
 * Records what became of an image an outside site's post showed (I-1703):
 * its map row of type outside-image, keyed by its address, with the run
 * that met it, created or replaced, so the report lists every one.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\LocksObjects;
use App\Domain\Import\Operations\Input\RecordOutsideImageInput;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Media\Models\Media;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class RecordOutsideImage extends BaseOperation
{
    use AuthorizesImports;
    use LocksObjects;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordOutsideImageInput $input */
        if ($input->key === '' || ! mb_check_encoding($input->key, 'UTF-8') || str_contains($input->key, "\0") || mb_strlen($input->key) > 500) {
            throw ValidationException::withMessages(['key' => 'An image address must be 1 to 500 characters.']);
        }

        $imported = in_array($input->outcome, [ImportOutcome::Created, ImportOutcome::Updated], true);

        if ($imported !== ($input->mediaId !== null) || ($input->mediaId !== null && ! Media::withTrashed()->whereKey($input->mediaId)->exists())) {
            throw ValidationException::withMessages(['media' => 'The item the image address leads to no longer exists.']);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var RecordOutsideImageInput $input */
        $ref = new SourceRef('outside-image', $input->key);
        $this->lockObject($input->sourceId, $ref);

        ImportMapEntry::query()->updateOrCreate(
            ['source_id' => $input->sourceId, 'object_type' => $ref->type, 'object_id' => $ref->id],
            [
                'teme_type' => $input->mediaId === null ? null : 'media',
                'teme_id' => $input->mediaId,
                'status' => $input->outcome->status(),
                'outcome' => $input->outcome,
                'source_hash' => null,
                'facts' => ['host' => $input->host],
                'run_id' => $input->runId,
                'imported_at' => now(),
            ],
        );

        return null;
    }
}

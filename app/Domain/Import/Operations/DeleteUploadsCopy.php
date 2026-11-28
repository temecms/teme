<?php

/**
 * Deletes a site's ready uploads copy on request, and the file Teme kept for
 * it, after the commit. Refused while an import from the site is running,
 * since the run may still be reading the copy. Needs `imports.run`.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportUpload;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\UploadsCopyUploadInput;
use App\Domain\Import\Uploads\KeptUploads;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class DeleteUploadsCopy extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UploadsCopyUploadInput $input */
        $upload = ImportUpload::query()->whereKey($input->uploadId)->where('state', 'ready')->first();

        if ($upload === null) {
            throw ValidationException::withMessages(['upload' => 'No uploads copy is attached to delete.']);
        }

        if (ImportRun::query()->where('source_id', $upload->source_id)->where('state', ImportRunState::Running->value)->exists()) {
            throw ValidationException::withMessages(['upload' => 'An import from this site is running, so its copy stays until that run ends.']);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var UploadsCopyUploadInput $input */
        $upload = ImportUpload::query()->whereKey($input->uploadId)->where('state', 'ready')->first();

        if ($upload !== null) {
            DB::afterCommit(fn () => KeptUploads::forget($upload));
            $upload->delete();
        }

        return null;
    }
}

<?php

/**
 * Drops an unfinished export-file upload: its bytes on the private disk and
 * its row. Needs `imports.run`.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\CancelImportUploadInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class CancelImportUpload extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CancelImportUploadInput $input */
        Validator::make(
            ['file' => $input->fileId],
            ['file' => ['required', 'string', Rule::exists('import_files', 'id')->whereNull('deleted_at')]],
        )->validate();
    }

    protected function perform(OperationInput $input): mixed
    {
        /** @var CancelImportUploadInput $input */
        $file = ImportFile::query()->whereKey($input->fileId)->lockForUpdate()->firstOrFail();

        if ($file->deleted_at !== null || $file->state !== 'uploading') {
            throw ValidationException::withMessages([
                'file' => 'That upload isn’t open any more.',
            ]);
        }

        $path = $file->path();
        $file->delete();
        Storage::disk('local')->delete($path);

        return null;
    }
}

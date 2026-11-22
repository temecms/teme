<?php

/**
 * Removes an export file’s bytes and its read rows, and marks the file
 * deleted. Needs `imports.run`.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\DeleteImportFileInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class DeleteImportFile extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var DeleteImportFileInput $input */
        Validator::make(
            ['file' => $input->fileId],
            ['file' => ['required', 'string', Rule::exists('import_files', 'id')->whereNull('deleted_at')]],
        )->validate();
    }

    protected function perform(OperationInput $input): mixed
    {
        /** @var DeleteImportFileInput $input */
        $file = ImportFile::query()->whereKey($input->fileId)->lockForUpdate()->firstOrFail();

        if ($file->deleted_at !== null) {
            throw ValidationException::withMessages([
                'file' => 'That export file is already gone.',
            ]);
        }

        $path = $file->path();
        DB::table('import_file_rows')->where('file_id', $file->id)->delete();
        Storage::disk('local')->delete($path);
        $file->forceFill(['deleted_at' => now()])->save();

        return null;
    }
}

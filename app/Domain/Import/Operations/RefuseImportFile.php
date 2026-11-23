<?php

/**
 * Refuses an uploaded file that can't be read as a WordPress export, with
 * the one sentence shown beside it. The rows read so far are removed; the
 * file's bytes stay until the file is deleted.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\RefuseImportFileInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Teme\Contracts\Operations\OperationInput;

final class RefuseImportFile extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RefuseImportFileInput $input */
        Validator::make(
            ['file' => $input->fileId, 'sentence' => $input->sentence],
            ['file' => ['required', 'string', Rule::exists('import_files', 'id')], 'sentence' => ['required', 'string', 'max:500']],
        )->validate();
    }

    /**
     * @return bool whether the file was refused now
     */
    protected function perform(OperationInput $input): bool
    {
        /** @var RefuseImportFileInput $input */
        $file = ImportFile::query()->whereKey($input->fileId)->lockForUpdate()->firstOrFail();

        if ($file->state !== 'reading') {
            return false;
        }

        DB::table('import_file_rows')->where('file_id', $file->id)->delete();
        $file->forceFill(['state' => 'refused', 'refused' => $input->sentence])->save();

        return true;
    }
}

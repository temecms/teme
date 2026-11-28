<?php

/**
 * Cancels an unfinished uploads copy: its row and the file Teme wrote for it
 * are deleted; the site's ready copy is left as it was. Needs `imports.run`.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportUpload;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\UploadsCopyUploadInput;
use App\Domain\Import\Uploads\KeptUploads;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;

final class CancelUploadsCopy extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        // The upload is checked where it is read, under its lock.
    }

    protected function perform(OperationInput $input): mixed
    {
        /** @var UploadsCopyUploadInput $input */
        $upload = ImportUpload::query()->whereKey($input->uploadId)->where('state', 'uploading')->first();

        if ($upload !== null) {
            DB::afterCommit(fn () => KeptUploads::forget($upload));
            $upload->delete();
        }

        return null;
    }
}

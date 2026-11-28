<?php

/**
 * Finishes an uploads copy once every byte has arrived and it opens as a zip:
 * it becomes the site's copy, and the copy it replaces is deleted when Teme
 * stored that one. A copy that is short or isn't a zip is refused, and its
 * file deleted. Needs `imports.run`.
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
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use ZipArchive;

final class FinishUploadsCopy extends BaseOperation
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

    /**
     * The copy's ID and site; when the file isn't a zip, the upload is deleted
     * and the answer names why, as a return rather than a throw, so the deletion
     * is kept.
     *
     * @return array{uploadId: string, sourceId: string, refused: string|null}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var UploadsCopyUploadInput $input */
        $upload = ImportUpload::query()->whereKey($input->uploadId)->where('state', 'uploading')->lockForUpdate()->first();

        if ($upload === null) {
            throw ValidationException::withMessages(['upload' => 'That upload isn’t open any more. Choose the file again.']);
        }

        if ((int) $upload->received_bytes !== (int) $upload->size_bytes) {
            throw ValidationException::withMessages(['upload' => 'The upload isn’t complete yet. Send the rest of the file.']);
        }

        $zip = new ZipArchive;

        if ($zip->open((string) $upload->path, ZipArchive::RDONLY) !== true) {
            KeptUploads::forget($upload);
            $upload->delete();

            return [
                'uploadId' => (string) $upload->id,
                'sourceId' => (string) $upload->source_id,
                'refused' => 'That file isn’t a zip of the uploads folder. Zip the wp-content/uploads folder itself and try again.',
            ];
        }

        $zip->close();

        $replaced = DB::transaction(function () use ($upload): ?ImportUpload {
            $previous = ImportUpload::query()->where('source_id', $upload->source_id)->where('state', 'ready')->lockForUpdate()->first();

            if ($previous !== null) {
                $previous->delete();
            }

            $upload->forceFill(['state' => 'ready'])->save();

            return $previous;
        });

        // The file of the copy it replaces goes only after the change is committed.
        if ($replaced !== null) {
            KeptUploads::forget($replaced);
        }

        return ['uploadId' => (string) $upload->id, 'sourceId' => (string) $upload->source_id, 'refused' => null];
    }
}

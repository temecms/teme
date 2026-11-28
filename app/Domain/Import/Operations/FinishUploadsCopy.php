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
use App\Domain\Import\Uploads\UploadsCopy;
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
        $refusal = null;
        $prefix = null;

        if ($zip->open((string) $upload->path, ZipArchive::RDONLY) !== true) {
            $refusal = 'That file isn’t a zip of the uploads folder. Zip the wp-content/uploads folder itself and try again.';
        } else {
            $prefix = UploadsCopy::prefixOfZip($zip);

            if (UploadsCopy::holdsTooMany($zip->numFiles)) {
                $refusal = 'That zip holds more files than a copy may. Zip the uploads folder by year, or attach it on the server.';
            } elseif ($prefix === null) {
                $refusal = 'The zip holds no uploads folder. Zip the wp-content/uploads folder itself and try again.';
            }

            $zip->close();
        }

        if ($refusal !== null) {
            DB::afterCommit(fn () => KeptUploads::forget($upload));
            $upload->delete();

            return [
                'uploadId' => (string) $upload->id,
                'sourceId' => (string) $upload->source_id,
                'refused' => $refusal,
            ];
        }

        $replaced = DB::transaction(function () use ($upload, $prefix): ?ImportUpload {
            $previous = ImportUpload::query()->where('source_id', $upload->source_id)->where('state', 'ready')->lockForUpdate()->first();

            if ($previous !== null) {
                $previous->delete();
            }

            $upload->forceFill(['state' => 'ready', 'prefix' => $prefix])->save();

            return $previous;
        });

        // The file of the copy it replaces goes only after the change is committed.
        if ($replaced !== null) {
            DB::afterCommit(fn () => KeptUploads::forget($replaced));
        }

        return ['uploadId' => (string) $upload->id, 'sourceId' => (string) $upload->source_id, 'refused' => null];
    }
}

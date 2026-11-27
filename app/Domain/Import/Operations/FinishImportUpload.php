<?php

/**
 * Closes an export-file upload once every byte is in: peeks the first 64 KB
 * for a WordPress export, stores its sha256, marks the file reading, and
 * after commit queues the reading job. A peek that fails marks the file
 * refused. Needs `imports.run`.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\File\ExportPeek;
use App\Domain\Import\File\Jobs\ReadImportFile;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\FinishImportUploadInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class FinishImportUpload extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var FinishImportUploadInput $input */
        Validator::make(
            ['file' => $input->fileId],
            ['file' => ['required', 'string', Rule::exists('import_files', 'id')->whereNull('deleted_at')]],
        )->validate();
    }

    /**
     * @return array{fileId: string, refused: bool, actorId: string|null}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var FinishImportUploadInput $input */
        $file = ImportFile::query()->whereKey($input->fileId)->lockForUpdate()->firstOrFail();

        if ($file->deleted_at !== null || $file->state !== 'uploading') {
            throw ValidationException::withMessages([
                'file' => 'That upload isn’t open any more. Choose the file again.',
            ]);
        }

        if ($file->received_bytes !== $file->size_bytes) {
            throw ValidationException::withMessages([
                'file' => "Send the next piece at byte {$file->received_bytes}.",
            ]);
        }

        $absolute = Storage::disk('local')->path($file->path());

        // A reader's file is judged by the reader when it is read, not by the WordPress export's head.
        if ($file->reader !== null) {
            $sha256 = hash_file('sha256', $absolute);

            if ($sha256 === false) {
                throw ValidationException::withMessages([
                    'file' => 'Teme couldn’t read the export file after the upload. Try again.',
                ]);
            }

            $file->forceFill(['sha256' => $sha256, 'state' => 'reading', 'refused' => null])->save();

            return ['fileId' => (string) $file->id, 'refused' => false, 'actorId' => $input->actorId()];
        }

        $handle = fopen($absolute, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ExportPeek::REFUSED,
            ]);
        }

        try {
            $head = fread($handle, ExportPeek::BYTES);
        } finally {
            fclose($handle);
        }

        if ($head === false || $head === '') {
            $file->forceFill([
                'state' => 'refused',
                'refused' => ExportPeek::REFUSED,
            ])->save();

            return ['fileId' => (string) $file->id, 'refused' => true, 'actorId' => $input->actorId()];
        }

        $why = ExportPeek::refuse($head);

        if ($why !== null) {
            $file->forceFill([
                'state' => 'refused',
                'refused' => $why,
            ])->save();

            return ['fileId' => (string) $file->id, 'refused' => true, 'actorId' => $input->actorId()];
        }

        $sha256 = hash_file('sha256', $absolute);

        if ($sha256 === false) {
            throw ValidationException::withMessages([
                'file' => 'Teme couldn’t read the export file after the upload. Try again.',
            ]);
        }

        // Mark reading here so the reading job finds the file ready; the
        // upload’s bytes are complete.
        $file->forceFill([
            'sha256' => $sha256,
            'state' => 'reading',
            'refused' => null,
        ])->save();

        return ['fileId' => (string) $file->id, 'refused' => false, 'actorId' => $input->actorId()];
    }

    /**
     * After commit: the reading job runs as whoever finished the upload.
     */
    protected function afterCommit(mixed $result): void
    {
        /** @var array{fileId: string, refused: bool, actorId: string|null} $result */
        if ($result['refused']) {
            return;
        }

        ReadImportFile::dispatch($result['fileId'], JobActor::of($result['actorId']));
    }
}

<?php

/**
 * Writes one piece of an export-file upload at its byte offset under a row
 * lock. A retry at an earlier offset truncates back first; a piece past the
 * bytes already kept is refused with the offset the server wants. Needs
 * `imports.run`.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\ReceiveImportPieceInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Teme\Contracts\Operations\OperationInput;

final class ReceiveImportPiece extends BaseOperation
{
    use AuthorizesImports;

    /** Largest piece the browser may send (8 MB). */
    public const int MOST_BYTES = 8 * 1024 * 1024;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReceiveImportPieceInput $input */
        Validator::make(
            [
                'file' => $input->fileId,
                'offset' => $input->offset,
                'bytes' => strlen($input->bytes),
            ],
            [
                'file' => ['required', 'string', Rule::exists('import_files', 'id')->whereNull('deleted_at')],
                'offset' => ['required', 'integer', 'min:0'],
                'bytes' => ['required', 'integer', 'min:1', 'max:'.self::MOST_BYTES],
            ],
            [
                'bytes.max' => 'Each piece may be at most 8 MB.',
                'bytes.min' => 'Send at least one byte in a piece.',
            ],
        )->validate();
    }

    /**
     * @return array{fileId: string, receivedBytes: int, sizeBytes: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ReceiveImportPieceInput $input */
        $file = ImportFile::query()->whereKey($input->fileId)->lockForUpdate()->firstOrFail();

        if ($file->deleted_at !== null || $file->state !== 'uploading') {
            throw ValidationException::withMessages([
                'file' => 'That upload isn’t open any more. Choose the file again.',
            ]);
        }

        if ($input->offset > $file->received_bytes) {
            throw ValidationException::withMessages([
                'offset' => "Send the next piece at byte {$file->received_bytes}.",
            ]);
        }

        $end = $input->offset + strlen($input->bytes);

        if ($end > $file->size_bytes) {
            throw ValidationException::withMessages([
                'bytes' => 'That piece goes past the end of the file.',
            ]);
        }

        $absolute = Storage::disk('local')->path($file->path());
        $folder = dirname($absolute);

        if (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder)) {
            throw new RuntimeException('Could not create the imports folder on the private disk.');
        }

        $handle = fopen($absolute, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Could not open the export file on the private disk.');
        }

        try {
            if (! ftruncate($handle, $input->offset)) {
                throw new RuntimeException('Could not truncate the export file to the piece’s offset.');
            }

            if (fseek($handle, $input->offset) !== 0) {
                throw new RuntimeException('Could not seek to the piece’s offset.');
            }

            $written = fwrite($handle, $input->bytes);

            if ($written === false || $written !== strlen($input->bytes)) {
                throw new RuntimeException('Could not write the export piece.');
            }

            if (! fflush($handle)) {
                throw new RuntimeException('Could not flush the export piece.');
            }
        } finally {
            fclose($handle);
        }

        @chmod($absolute, 0600);

        $file->forceFill(['received_bytes' => $end])->save();

        return [
            'fileId' => (string) $file->id,
            'receivedBytes' => $end,
            'sizeBytes' => (int) $file->size_bytes,
        ];
    }
}

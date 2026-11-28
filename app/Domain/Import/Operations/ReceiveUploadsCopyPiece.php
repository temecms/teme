<?php

/**
 * Writes one piece of an uploads copy at its byte offset, under a row lock,
 * the way an export file's piece is written: a retry at an earlier offset
 * truncates back first, and a piece past the bytes kept is refused with the
 * offset the server wants. Needs `imports.run`.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportUpload;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\ReceiveUploadsCopyPieceInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Teme\Contracts\Operations\OperationInput;

final class ReceiveUploadsCopyPiece extends BaseOperation
{
    use AuthorizesImports;

    /** The largest piece the browser may send (8 MB), as an export file's. */
    public const int MOST_BYTES = 8 * 1024 * 1024;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReceiveUploadsCopyPieceInput $input */
        Validator::make(
            ['upload' => $input->uploadId, 'offset' => $input->offset, 'bytes' => strlen($input->bytes)],
            [
                'upload' => ['required', 'string', Rule::exists('import_uploads', 'id')],
                'offset' => ['required', 'integer', 'min:0'],
                'bytes' => ['required', 'integer', 'min:1', 'max:'.self::MOST_BYTES],
            ],
            ['bytes.max' => 'Each piece may be at most 8 MB.', 'bytes.min' => 'Send at least one byte in a piece.'],
        )->validate();
    }

    /**
     * @return array{uploadId: string, receivedBytes: int, sizeBytes: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ReceiveUploadsCopyPieceInput $input */
        $upload = ImportUpload::query()->whereKey($input->uploadId)->where('state', 'uploading')->lockForUpdate()->first();

        if ($upload === null) {
            throw ValidationException::withMessages(['upload' => 'That upload isn’t open any more. Choose the file again.']);
        }

        if ($input->offset > $upload->received_bytes) {
            throw ValidationException::withMessages(['offset' => "Send the next piece at byte {$upload->received_bytes}."]);
        }

        $end = $input->offset + strlen($input->bytes);

        if ($end > $upload->size_bytes) {
            throw ValidationException::withMessages(['bytes' => 'That piece goes past the end of the file.']);
        }

        $path = (string) $upload->path;
        $folder = dirname($path);

        if (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder)) {
            throw new RuntimeException('Could not create the uploads folder on the private disk.');
        }

        $handle = fopen($path, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Could not open the uploads copy on the private disk.');
        }

        try {
            if (! ftruncate($handle, $input->offset) || fseek($handle, $input->offset) !== 0) {
                throw new RuntimeException('Could not seek to the piece’s offset in the uploads copy.');
            }

            $written = fwrite($handle, $input->bytes);

            if ($written === false || $written !== strlen($input->bytes) || ! fflush($handle)) {
                throw new RuntimeException('Could not write the uploads copy piece.');
            }
        } finally {
            fclose($handle);
        }

        @chmod($path, 0600);

        $upload->forceFill(['received_bytes' => $end])->save();

        return ['uploadId' => (string) $upload->id, 'receivedBytes' => $end, 'sizeBytes' => (int) $upload->size_bytes];
    }
}

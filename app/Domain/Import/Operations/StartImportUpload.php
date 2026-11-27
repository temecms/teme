<?php

/**
 * Begins an export-file upload, or returns the same person’s unfinished one
 * of that name and size from the last day so a dropped connection can carry
 * on. Refuses a file over the configured size limit and a name that isn’t
 * `.xml`. Needs `imports.run`.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\StartImportUploadInput;
use App\Domain\Plugins\Readers\ImportReaders;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class StartImportUpload extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var StartImportUploadInput $input */
        Validator::make(
            ['name' => $input->name, 'size' => $input->sizeBytes],
            [
                'name' => ['required', 'string', 'min:1', 'max:255'],
                'size' => ['required', 'integer', 'min:1'],
            ],
            [
                'name.required' => 'Choose a WordPress export file.',
                'name.max' => 'That file name is too long.',
                'size.min' => 'Choose a WordPress export file.',
            ],
        )->validate();

        if ($input->reader !== null) {
            $offer = app(ImportReaders::class)->find($input->reader);

            if ($offer === null) {
                throw ValidationException::withMessages([
                    'reader' => 'That import reader isn’t on this site any more.',
                ]);
            }

            if (! in_array(strtolower(pathinfo($input->name, PATHINFO_EXTENSION)), $offer->fileTypes, true)) {
                throw ValidationException::withMessages([
                    'name' => 'Choose a file the reader reads: '.implode(', ', array_map(fn (string $type): string => '.'.$type, $offer->fileTypes)).'.',
                ]);
            }
        } elseif (! str_ends_with(strtolower($input->name), '.xml')) {
            throw ValidationException::withMessages([
                'name' => 'Choose the .xml file from WordPress’s Tools › Export.',
            ]);
        }

        $limitMb = max(1, (int) config('teme.import.max_file_mb'));
        $limitBytes = $limitMb * 1024 * 1024;

        if ($input->sizeBytes > $limitBytes) {
            $gigabytes = max(1, intdiv($limitMb, 1024));

            throw ValidationException::withMessages([
                'size' => "This file is larger than {$gigabytes} GB. Export a part of the site at a time: WordPress’s Tools › Export can export one type, or a range of dates.",
            ]);
        }
    }

    /**
     * @return array{fileId: string, carriedOn: bool, receivedBytes: int, sizeBytes: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var StartImportUploadInput $input */
        $actorId = $input->actorId() === null ? null : (int) $input->actorId();

        $existing = ImportFile::query()
            ->where('uploaded_by', $actorId)
            ->where('name', $input->name)
            ->where('size_bytes', $input->sizeBytes)
            ->where('state', 'uploading')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subDay())
            ->where('reader', $input->reader)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return [
                'fileId' => (string) $existing->id,
                'carriedOn' => true,
                'receivedBytes' => (int) $existing->received_bytes,
                'sizeBytes' => (int) $existing->size_bytes,
            ];
        }

        $file = ImportFile::query()->create([
            'uploaded_by' => $actorId,
            'name' => $input->name,
            'size_bytes' => $input->sizeBytes,
            'received_bytes' => 0,
            'state' => 'uploading',
            'reader' => $input->reader,
        ]);

        return [
            'fileId' => (string) $file->id,
            'carriedOn' => false,
            'receivedBytes' => 0,
            'sizeBytes' => (int) $file->size_bytes,
        ];
    }
}

<?php

/**
 * Attaches a copy of an old site's uploads folder to the site's import: a
 * folder or a .zip already on this server, read in place and never deleted
 * or changed. The site is named by its ID or its address. One copy per site;
 * attaching again replaces it. Needs `imports.run`, or the operator's shell.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Models\ImportUpload;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\AttachUploadsCopyInput;
use App\Domain\Import\Uploads\KeptUploads;
use App\Domain\Import\Uploads\UploadsCopy;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use ZipArchive;

final class AttachUploadsCopy extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AttachUploadsCopyInput $input */
        if ($this->sourceFor($input->source) === null) {
            throw ValidationException::withMessages(['source' => 'No import site matches that ID or address.']);
        }

        $this->kindOf($input->path);
    }

    /**
     * @return array{sourceId: string, kind: string, path: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var AttachUploadsCopyInput $input */
        $source = $this->sourceFor($input->source);
        $path = $this->kindOf($input->path);

        $replaced = DB::transaction(function () use ($source, $path, $input): ?ImportUpload {
            $previous = ImportUpload::query()->where('source_id', $source->id)->where('state', 'ready')->lockForUpdate()->first();

            if ($previous !== null) {
                $previous->delete();
            }

            ImportUpload::query()->create([
                'source_id' => $source->id,
                'state' => 'ready',
                'kind' => $path['kind'],
                'path' => $path['path'],
                'name' => basename($path['path']),
                'size_bytes' => $path['kind'] === UploadsCopy::ZIP ? (int) filesize($path['path']) : 0,
                'received_bytes' => $path['kind'] === UploadsCopy::ZIP ? (int) filesize($path['path']) : 0,
                'kept_by_teme' => false,
                'attached_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            ]);

            return $previous;
        });

        // A copy Teme stored is deleted only after the change is committed; one the operator named never is.
        if ($replaced !== null) {
            KeptUploads::forget($replaced);
        }

        return ['sourceId' => (string) $source->id, 'kind' => $path['kind'], 'path' => $path['path']];
    }

    /**
     * The site named by its ID or its address, or null.
     */
    private function sourceFor(string $reference): ?ImportSource
    {
        return ImportSource::query()
            ->where(fn ($query) => $query->whereKey($reference)->orWhere('address', $reference))
            ->first();
    }

    /**
     * The folder or zip's kind and its real path, or a refusal naming what is wrong.
     *
     * @return array{kind: string, path: string}
     *
     * @throws ValidationException
     */
    private function kindOf(string $path): array
    {
        $real = realpath($path);

        if ($real !== false && is_dir($real) && is_readable($real)) {
            return ['kind' => UploadsCopy::FOLDER, 'path' => $real];
        }

        $zip = new ZipArchive;

        if ($real !== false && is_file($real) && strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'zip' && $zip->open($real, ZipArchive::RDONLY) === true) {
            $zip->close();

            return ['kind' => UploadsCopy::ZIP, 'path' => $real];
        }

        throw ValidationException::withMessages(['path' => 'Name a folder or a .zip of the uploads folder that this server can read.']);
    }
}

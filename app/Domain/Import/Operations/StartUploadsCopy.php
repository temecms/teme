<?php

/**
 * Starts uploading a copy of an old site's uploads folder, as a .zip in
 * pieces, for the site's import. Starting again drops the unfinished upload
 * of the same site. A zip larger than the uploads cap is refused. Needs
 * `imports.run`.
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
use App\Domain\Import\Operations\Input\StartUploadsCopyInput;
use App\Domain\Import\Uploads\KeptUploads;
use App\Domain\Import\Uploads\UploadsCopy;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class StartUploadsCopy extends BaseOperation
{
    use AuthorizesImports;

    /** The folder, on the private disk, a browser-uploaded copy is kept in. */
    public const string FOLDER = 'imports/uploads';

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var StartUploadsCopyInput $input */
        Validator::make(
            ['name' => $input->name, 'size' => $input->sizeBytes],
            [
                'name' => ['required', 'string', 'max:255'],
                'size' => ['required', 'integer', 'min:1'],
            ],
            ['name.required' => 'Choose a .zip of the uploads folder.', 'size.min' => 'Choose a .zip of the uploads folder.'],
        )->validate();

        if (! str_ends_with(strtolower($input->name), '.zip')) {
            throw ValidationException::withMessages(['name' => 'Choose a .zip of the uploads folder. Zip the wp-content/uploads folder itself.']);
        }

        $limitBytes = max(1, (int) config('teme.import.max_uploads_mb')) * 1024 * 1024;

        if ($input->sizeBytes > $limitBytes) {
            $gigabytes = max(1, intdiv(max(1, (int) config('teme.import.max_uploads_mb')), 1024));

            throw ValidationException::withMessages(['size' => "This copy is larger than {$gigabytes} GB. Attach a folder or a zip on the server instead, with teme:import:uploads."]);
        }
    }

    /**
     * @return array{uploadId: string, receivedBytes: int, sizeBytes: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var StartUploadsCopyInput $input */
        $source = ImportSource::query()->whereKey($input->sourceId)->first();

        if ($source === null) {
            throw ValidationException::withMessages(['source' => 'No import site matches that ID.']);
        }

        $id = strtolower((string) Str::ulid());
        $path = Storage::disk('local')->path(self::FOLDER.'/'.$id.'.zip');

        return DB::transaction(function () use ($source, $input, $id, $path): array {
            foreach (ImportUpload::query()->where('source_id', $source->id)->where('state', 'uploading')->lockForUpdate()->get() as $unfinished) {
                DB::afterCommit(fn () => KeptUploads::forget($unfinished));
                $unfinished->delete();
            }

            $upload = ImportUpload::query()->create([
                'id' => $id,
                'source_id' => $source->id,
                'state' => 'uploading',
                'kind' => UploadsCopy::ZIP,
                'path' => $path,
                'name' => $input->name,
                'size_bytes' => $input->sizeBytes,
                'received_bytes' => 0,
                'kept_by_teme' => true,
                'attached_by' => $input->actorId === null ? null : (int) $input->actorId,
            ]);

            return [
                'uploadId' => (string) $upload->id,
                'receivedBytes' => 0,
                'sizeBytes' => (int) $upload->size_bytes,
            ];
        });
    }
}

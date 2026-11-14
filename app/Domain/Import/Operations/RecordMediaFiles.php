<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\RecordMediaFilesInput;
use App\Domain\Media\Models\Media;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Records an attachment's file addresses in the import map (I-338): one row
 * of type file per key no row of the source has yet, leading to its media
 * item, in no run, so a run's counts stay the old site's objects. A key
 * another item holds stays theirs; none is ever moved or deleted.
 */
final class RecordMediaFiles extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordMediaFilesInput $input */
        $keys = $input->keys;

        foreach ($keys as $key) {
            if ($key === '' || ! mb_check_encoding($key, 'UTF-8') || str_contains($key, "\0") || mb_strlen($key) > 500) {
                throw ValidationException::withMessages(['keys' => 'A file address must be 1 to 500 characters.']);
            }
        }

        /** @var RecordMediaFilesInput $input */
        if (! Media::withTrashed()->whereKey($input->mediaId)->exists()) {
            throw ValidationException::withMessages(['media' => 'The item the file addresses lead to no longer exists.']);
        }
    }

    /**
     * @return list<string> the keys another attachment's item already holds
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RecordMediaFilesInput $input */
        $keys = array_values(array_unique($input->keys));

        if ($keys === []) {
            return [];
        }

        $now = now();
        $facts = json_encode(['attachment' => $input->attachmentId], JSON_THROW_ON_ERROR);

        DB::table('import_map')->insertOrIgnore(array_map(fn (string $key): array => [
            'id' => strtolower((string) Str::ulid()),
            'source_id' => $input->sourceId,
            'object_type' => 'file',
            'object_id' => $key,
            'teme_type' => 'media',
            'teme_id' => $input->mediaId,
            'status' => ImportStatus::Imported->value,
            'outcome' => ImportOutcome::Created->value,
            'facts' => $facts,
            'run_id' => null,
            'imported_at' => $now,
        ], $keys));

        return DB::table('import_map')
            ->where('source_id', $input->sourceId)
            ->where('object_type', 'file')
            ->whereIn('object_id', $keys)
            ->where('teme_id', '<>', $input->mediaId)
            // The attachment's own rows for an earlier file of its own (one it used before an edit) aren't another's.
            ->whereRaw("facts->'attachment' IS DISTINCT FROM ?::jsonb", [(string) $input->attachmentId])
            ->orderBy('object_id')
            ->pluck('object_id')
            ->map(fn (mixed $key): string => (string) $key)
            ->values()
            ->all();
    }
}

<?php

/**
 * What Tools › Import reads about uploaded export files: a file’s progress
 * for the upload form, and nothing that writes.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Import\File\ItemRows;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Phases\Addresses\WordPressAddresses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ImportFiles
{
    /** How long an uploaded file is kept after the last import from it ends. */
    public const int KEEP_DAYS = 30;

    /**
     * Returns one file as the upload form needs it, or null when it isn’t there.
     *
     * @return array{id: string, name: string, sizeBytes: int, receivedBytes: int, state: string, refused: string|null, sourceId: string|null}|null
     */
    public function status(string $fileId): ?array
    {
        $file = ImportFile::query()->whereKey($fileId)->whereNull('deleted_at')->first();

        if ($file === null) {
            return null;
        }

        return [
            'id' => (string) $file->id,
            'name' => $file->name,
            'sizeBytes' => (int) $file->size_bytes,
            'receivedBytes' => (int) $file->received_bytes,
            'state' => $file->state,
            'refused' => $file->refused,
            'sourceId' => $file->source_id === null ? null : (string) $file->source_id,
        ];
    }

    /**
     * Returns the export file linked to a source, if any, for the sites list’s subtitle.
     *
     * @return array{uploadedAt: string}|null
     */
    public function forSource(string $sourceId): ?array
    {
        $file = ImportFile::query()
            ->where('source_id', $sourceId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();

        if ($file === null) {
            return null;
        }

        return [
            'uploadedAt' => $file->created_at->utc()->toIso8601String(),
        ];
    }

    /**
     * Returns the newest of a site's files that has been read and not deleted, or null when it has none.
     */
    public function current(string $sourceId): ?ImportFile
    {
        return ImportFile::query()
            ->where('source_id', $sourceId)
            ->where('state', 'ready')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Returns the authors an export file names, with how many of its posts and pages each wrote: their login, name and email, by their ID in the file.
     *
     * @return list<array{id: int, login: string, name: string, email: string, items: int}>
     */
    public function authors(string $fileId): array
    {
        $items = DB::table('import_file_rows')->where('file_id', $fileId)->where('list', ItemRows::CONTENT)
            ->selectRaw("(payload->>'author')::bigint AS author, count(*) AS total")->groupByRaw('1')->pluck('total', 'author');
        $authors = [];

        foreach (DB::table('import_file_rows')->where('file_id', $fileId)->where('list', ItemRows::USERS)->orderBy('object_id')->get(['object_id', 'payload']) as $row) {
            $person = json_decode((string) $row->payload, true);
            $person = is_array($person) ? $person : [];
            $login = (string) ($person['login'] ?? $person['nicename'] ?? '');
            $name = trim((string) ($person['display_name'] ?? ''));

            $authors[] = [
                'id' => (int) $row->object_id,
                'login' => $login,
                'name' => $name !== '' ? $name : $login,
                'email' => strtolower(trim((string) ($person['email'] ?? ''))),
                'items' => (int) ($items[(int) $row->object_id] ?? 0),
            ];
        }

        return $authors;
    }

    /**
     * Returns how many posts, pages and media files an export file lists, for its plan's choices.
     *
     * @return array{postCount: int, pageCount: int, mediaCount: int}
     */
    public function counts(string $fileId): array
    {
        $types = DB::table('import_file_rows')->where('file_id', $fileId)->where('list', ItemRows::CONTENT)
            ->selectRaw("payload->>'type' AS type, count(*) AS total")->groupByRaw('1')->pluck('total', 'type');

        return [
            'postCount' => (int) ($types[WordPressAddresses::POSTS] ?? 0),
            'pageCount' => (int) ($types[WordPressAddresses::PAGES] ?? 0),
            'mediaCount' => DB::table('import_file_rows')->where('file_id', $fileId)->where('list', ItemRows::MEDIA)->count(),
        ];
    }

    /**
     * Returns the day Teme deletes a file by itself, 30 days after the last import from it ended, as an ISO 8601 time; null before any import from it has ended, or once it is deleted.
     */
    public function deletesOn(string $fileId): ?string
    {
        $file = ImportFile::query()->whereKey($fileId)->whereNull('deleted_at')->first();
        $ended = $file === null ? null : ImportRun::query()->where('file_id', $fileId)->whereNotNull('ended_at')->max('ended_at');

        return $ended === null ? null : CarbonImmutable::parse((string) $ended)->addDays(self::KEEP_DAYS)->utc()->toIso8601String();
    }

    /**
     * Returns the IDs of the files Teme deletes by itself now: not deleted, with no import from them running, and whose last import ended more than 30 days ago.
     *
     * @return list<string>
     */
    public function due(): array
    {
        return ImportFile::query()
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($runs) => $runs->selectRaw('1')->from('import_runs')->whereColumn('import_runs.file_id', 'import_files.id')->whereNull('ended_at'))
            ->whereExists(fn ($runs) => $runs->selectRaw('1')->from('import_runs')->whereColumn('import_runs.file_id', 'import_files.id')->whereNotNull('ended_at'))
            ->whereRaw('(SELECT max(ended_at) FROM import_runs WHERE import_runs.file_id = import_files.id) < ?', [now()->subDays(self::KEEP_DAYS)])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }
}

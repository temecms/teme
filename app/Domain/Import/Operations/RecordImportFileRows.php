<?php

/**
 * Keeps one part of an export file's read: its rows, written over any the
 * same item wrote before, what the report counts from them, the channel's
 * facts, and where the next part starts. A part that doesn't start where the
 * file's read stands, or a file no longer being read, writes nothing.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\File\ItemRows;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\RecordImportFileRowsInput;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Teme\Contracts\Operations\OperationInput;
use Throwable;

final class RecordImportFileRows extends BaseOperation
{
    use AuthorizesImports;

    /** Rows per statement, well under PostgreSQL's limit on a statement's values. */
    private const int CHUNK = 500;

    /** The objects dropped from serialized meta that the report names, at most. */
    private const int DROPPED = 50;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordImportFileRowsInput $input */
        Validator::make(
            ['file' => $input->fileId, 'from' => $input->fromItem, 'to' => $input->toItem],
            ['file' => ['required', 'string', Rule::exists('import_files', 'id')], 'from' => ['integer', 'min:0'], 'to' => ['integer', 'gte:from']],
        )->validate();
    }

    /**
     * @return bool whether the part was kept
     */
    protected function perform(OperationInput $input): bool
    {
        /** @var RecordImportFileRowsInput $input */
        $file = ImportFile::query()->whereKey($input->fileId)->lockForUpdate()->firstOrFail();

        if ($file->state !== 'reading' || $file->deleted_at !== null || $file->read_items !== $input->fromItem) {
            return false;
        }

        $rows = [];

        foreach ($input->rows as $row) {
            // The last of an object's rows in the part wins, as a later write over an earlier one would.
            $rows[$row['list']."\0".$row['id']] = [
                'file_id' => $file->id,
                'list' => $row['list'],
                'object_id' => $row['id'],
                'payload' => json_encode($row['payload'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            ];
        }

        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            DB::table('import_file_rows')->upsert($chunk, ['file_id', 'list', 'object_id'], ['payload']);
        }

        $this->keepFacts($file->id, $input->facts);

        $file->forceFill([
            'read_items' => $input->toItem,
            'site_address' => $input->channel['siteAddress'],
            'wxr_version' => $input->channel['version'],
            'made_at' => self::moment($input->channel['madeAt']),
        ])->save();

        return true;
    }

    /**
     * Adds the part's counts to the file's own row of them.
     *
     * @param  array{seo: list<string>, images: array{total: int, own: int, hosts: array<string, int>}, dropped: list<array{post_id: int, key: string}>, notes?: array<string, array{count: int, examples: list<string>, sentence: string}>, readerCursor?: string|null, readerGiven?: list<string>, readerResolving?: bool, replaceNotes?: bool}  $facts
     */
    private function keepFacts(string $fileId, array $facts): void
    {
        $stored = DB::table('import_file_rows')->where(['file_id' => $fileId, 'list' => ItemRows::FACTS, 'object_id' => 0])->value('payload');
        $kept = is_string($stored) ? json_decode($stored, true) : null;
        $kept = is_array($kept) ? $kept : [];

        $seo = is_array($kept['seo'] ?? null) ? $kept['seo'] : [];
        foreach ($facts['seo'] as $plugin) {
            $seo[$plugin] = (int) ($seo[$plugin] ?? 0) + 1;
        }

        $images = is_array($kept['images'] ?? null) ? $kept['images'] : ['total' => 0, 'own' => 0, 'hosts' => []];
        $images['total'] = (int) ($images['total'] ?? 0) + $facts['images']['total'];
        $images['own'] = (int) ($images['own'] ?? 0) + $facts['images']['own'];
        $hosts = is_array($images['hosts'] ?? null) ? $images['hosts'] : [];
        foreach ($facts['images']['hosts'] as $host => $count) {
            $hosts[$host] = (int) ($hosts[$host] ?? 0) + $count;
        }
        $images['hosts'] = $hosts;

        $dropped = is_array($kept['dropped'] ?? null) ? $kept['dropped'] : [];

        $notes = ($facts['replaceNotes'] ?? false) === true
            ? []
            : (is_array($kept['notes'] ?? null) ? $kept['notes'] : []);

        foreach ($facts['notes'] ?? [] as $kind => $note) {
            $held = is_array($notes[$kind] ?? null) ? $notes[$kind] : ['count' => 0, 'examples' => [], 'sentence' => $note['sentence']];
            $notes[$kind] = [
                'count' => (int) $held['count'] + $note['count'],
                'examples' => array_slice([...$held['examples'], ...$note['examples']], 0, 5),
                'sentence' => $held['sentence'],
            ];
        }

        $payload = [
            'seo' => $seo,
            'images' => $images,
            'dropped' => array_slice([...$dropped, ...$facts['dropped']], 0, self::DROPPED),
            'dropped_count' => (int) ($kept['dropped_count'] ?? 0) + count($facts['dropped']),
            'notes' => $notes,
        ];

        if (array_key_exists('readerCursor', $facts)) {
            $payload['readerCursor'] = $facts['readerCursor'];
        } elseif (array_key_exists('readerCursor', $kept)) {
            $payload['readerCursor'] = $kept['readerCursor'];
        }

        if (array_key_exists('readerGiven', $facts)) {
            $payload['readerGiven'] = $facts['readerGiven'];
        } elseif (is_array($kept['readerGiven'] ?? null)) {
            $payload['readerGiven'] = $kept['readerGiven'];
        }

        if (array_key_exists('readerResolving', $facts)) {
            $payload['readerResolving'] = (bool) $facts['readerResolving'];
        } elseif (array_key_exists('readerResolving', $kept)) {
            $payload['readerResolving'] = (bool) $kept['readerResolving'];
        }

        DB::table('import_file_rows')->upsert(
            [['file_id' => $fileId, 'list' => ItemRows::FACTS, 'object_id' => 0, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]],
            ['file_id', 'list', 'object_id'],
            ['payload'],
        );
    }

    /**
     * The file's own date, or null when it has none or it can't be read.
     */
    private static function moment(?string $date): ?CarbonImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}

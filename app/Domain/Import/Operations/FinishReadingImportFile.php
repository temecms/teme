<?php

/**
 * Finishes reading an export file once its last item is read: puts its menu
 * items under their menus, counts each author's posts and each term's items,
 * keeps the file's site report, and makes the file a site to import, the
 * one Teme already knows at the same address or a new one. Who may import
 * may call it; a file no longer being read is left as it is.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\File\FileReport;
use App\Domain\Import\File\ItemRows;
use App\Domain\Import\File\MenuRows;
use App\Domain\Import\File\WxrRefused;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\FinishReadingImportFileInput;
use App\Domain\Import\Reader\ReaderSite;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Teme\Contracts\Operations\OperationInput;

final class FinishReadingImportFile extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var FinishReadingImportFileInput $input */
        Validator::make(
            ['file' => $input->fileId],
            ['file' => ['required', 'string', Rule::exists('import_files', 'id')]],
        )->validate();
    }

    /**
     * @return string|null the site's ID, or null when the file was no longer being read
     */
    protected function perform(OperationInput $input): ?string
    {
        /** @var FinishReadingImportFileInput $input */
        $file = ImportFile::query()->whereKey($input->fileId)->lockForUpdate()->firstOrFail();

        if ($file->state !== 'reading' || $file->deleted_at !== null) {
            return null;
        }

        $address = $file->site_address === null ? '' : ReaderSite::address($file->site_address);

        if ($address === '') {
            DB::table('import_file_rows')->where('file_id', $file->id)->delete();
            $file->forceFill(['state' => 'refused', 'refused' => WxrRefused::notAnExport()->sentence])->save();

            return null;
        }

        $kind = $file->reader ?? 'wordpress';
        $source = ImportSource::query()->where('address', $address)->lockForUpdate()->first();

        if ($source !== null && $source->kind !== $kind) {
            DB::table('import_file_rows')->where('file_id', $file->id)->delete();
            $file->forceFill(['state' => 'refused', 'refused' => 'This site was imported from another system, so this file can’t join its import.'])->save();

            return null;
        }

        $this->placeMenus($file->id);
        $this->count($file->id);

        $source ??= ImportSource::query()->create(['address' => $address, 'created_by' => $input->actorId() === null ? null : (int) $input->actorId(), 'kind' => $kind]);

        $file->forceFill([
            'report' => FileReport::build($file->id, $address, $file->made_at),
            'source_id' => $source->id,
            'state' => 'ready',
        ])->save();

        return (string) $source->id;
    }

    /**
     * Writes each menu's row with its items, each showing its target's own title where it has none.
     */
    private function placeMenus(string $fileId): void
    {
        $rows = fn (string $list) => DB::table('import_file_rows')->where('file_id', $fileId)->where('list', $list);
        $items = $rows(ItemRows::MENU_ITEMS)->orderBy('object_id')->pluck('payload')->map(fn (mixed $payload): array => self::decoded($payload))->all();
        $menus = $rows(ItemRows::OTHER_TERMS)->whereRaw("payload->>'taxonomy' = 'nav_menu'")->orderBy('object_id')->pluck('payload')->map(fn (mixed $payload): array => self::decoded($payload))->all();

        $wanted = fn (string $type): array => array_values(array_unique(array_map(
            fn (array $item): int => (int) $item['object_id'],
            array_filter($items, fn (array $item): bool => ($item['type'] ?? null) === $type),
        )));
        $posts = [];
        $terms = [];

        foreach ($rows(ItemRows::CONTENT)->whereIn('object_id', $wanted('post_type'))->get(['object_id', 'payload']) as $row) {
            $payload = self::decoded($row->payload);
            $posts[(int) $row->object_id] = ['title' => (string) ($payload['title'] ?? ''), 'url' => (string) ($payload['permalink'] ?? '')];
        }

        foreach ($rows(ItemRows::TERMS)->whereIn('object_id', $wanted('taxonomy'))->get(['object_id', 'payload']) as $row) {
            $payload = self::decoded($row->payload);
            $terms[(int) $row->object_id] = ['title' => (string) ($payload['name'] ?? ''), 'url' => ''];
        }

        $assembled = MenuRows::assemble($items, $menus, fn (int $id): ?array => $posts[$id] ?? null, fn (int $id): ?array => $terms[$id] ?? null);

        $rows(ItemRows::MENUS)->delete();
        DB::table('import_file_rows')->insert(array_map(fn (array $row): array => [
            'file_id' => $fileId,
            'list' => $row['list'],
            'object_id' => $row['id'],
            'payload' => json_encode($row['payload'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ], $assembled));
    }

    /**
     * Counts each author's items and published posts, and each term's published items as WordPress counts them.
     */
    private function count(string $fileId): void
    {
        DB::update(<<<'SQL'
            UPDATE import_file_rows AS person
            SET payload = person.payload || jsonb_build_object('published_posts', counted.published, 'items', counted.total)
            FROM (
                SELECT (payload->>'author')::bigint AS author,
                       count(*) AS total,
                       count(*) FILTER (WHERE payload->>'type' = 'post' AND payload->>'status' = 'publish') AS published
                FROM import_file_rows
                WHERE file_id = ? AND list = ?
                GROUP BY 1
            ) AS counted
            WHERE person.file_id = ? AND person.list = ? AND person.object_id = counted.author
            SQL, [$fileId, ItemRows::CONTENT, $fileId, ItemRows::USERS]);

        DB::update(<<<'SQL'
            UPDATE import_file_rows
            SET payload = payload || '{"items": 0, "published_posts": 0}'::jsonb
            WHERE file_id = ? AND list = ? AND NOT jsonb_exists(payload, 'items')
            SQL, [$fileId, ItemRows::USERS]);

        DB::update(<<<'SQL'
            UPDATE import_file_rows AS term
            SET payload = jsonb_set(term.payload, '{count}', to_jsonb(counted.total))
            FROM (
                SELECT ids.id::bigint AS id, count(*) AS total
                FROM import_file_rows AS item,
                    jsonb_each(CASE WHEN jsonb_typeof(item.payload->'terms') = 'object' THEN item.payload->'terms' ELSE '{}'::jsonb END) AS taxonomy,
                    jsonb_array_elements_text(taxonomy.value) AS ids(id)
                WHERE item.file_id = ? AND item.list = ? AND item.payload->>'status' = 'publish'
                GROUP BY 1
            ) AS counted
            WHERE term.file_id = ? AND term.list = ? AND term.object_id = counted.id
            SQL, [$fileId, ItemRows::CONTENT, $fileId, ItemRows::TERMS]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}

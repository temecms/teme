<?php

/**
 * Fills a reader file's later-page references from the engine IDs already
 * assigned: parents, authors, featured pictures, terms, posts, menus and
 * menu targets, by joining each row's stored source IDs to import_reader_ids.
 * Who may import may call it; the reader is not opened again.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\File\ItemRows;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\ResolveImportReaderRowsInput;
use App\Domain\Import\Reader\ReaderRows;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Teme\Contracts\Import\ImportKind;
use Teme\Contracts\Operations\OperationInput;

final class ResolveImportReaderRows extends BaseOperation
{
    use AuthorizesImports;

    private const int CHUNK = 500;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ResolveImportReaderRowsInput $input */
        Validator::make(
            ['file' => $input->fileId, 'address' => $input->siteAddress, 'reader' => $input->reader],
            [
                'file' => ['required', 'string', Rule::exists('import_files', 'id')],
                'address' => ['required', 'string', 'max:2048'],
                'reader' => ['required', 'string', 'max:200'],
            ],
        )->validate();
    }

    /**
     * How many rows were rewritten.
     */
    protected function perform(OperationInput $input): int
    {
        /** @var ResolveImportReaderRowsInput $input */
        $rewritten = 0;
        $after = 0;

        do {
            $rows = DB::table('import_file_rows')
                ->where('file_id', $input->fileId)
                ->where('object_id', '>', $after)
                ->whereRaw('jsonb_exists(payload, ?)', ['reader_refs'])
                ->orderBy('object_id')
                ->limit(self::CHUNK)
                ->get(['list', 'object_id', 'payload']);

            if ($rows->isEmpty()) {
                break;
            }

            $wanted = [];

            foreach ($rows as $row) {
                $payload = is_string($row->payload) ? json_decode($row->payload, true) : null;
                $refs = is_array($payload) && is_array($payload['reader_refs'] ?? null) ? $payload['reader_refs'] : [];
                $after = max($after, (int) $row->object_id);

                foreach ($this->sourcesOf((string) $row->list, $refs) as [$kind, $sourceId]) {
                    $wanted[$kind][$sourceId] = true;
                }
            }

            $ids = $this->lookup($input->siteAddress, $input->reader, $wanted);
            $writes = [];

            foreach ($rows as $row) {
                $payload = is_string($row->payload) ? json_decode($row->payload, true) : null;

                if (! is_array($payload) || ! is_array($payload['reader_refs'] ?? null)) {
                    continue;
                }

                $next = $this->filled((string) $row->list, $payload, $ids, $input->fileId);

                if ($next === $payload) {
                    continue;
                }

                $writes[] = [
                    'file_id' => $input->fileId,
                    'list' => $row->list,
                    'object_id' => (int) $row->object_id,
                    'payload' => json_encode($next, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                ];
            }

            foreach (array_chunk($writes, self::CHUNK) as $chunk) {
                DB::table('import_file_rows')->upsert($chunk, ['file_id', 'list', 'object_id'], ['payload']);
                $rewritten += count($chunk);
            }
        } while ($rows->count() === self::CHUNK);

        return $rewritten;
    }

    /**
     * The source IDs a list's refs name, each with the kind to look up.
     *
     * @param  array<string, mixed>  $refs
     *
     * @return list<array{0: string, 1: string}>
     */
    private function sourcesOf(string $list, array $refs): array
    {
        $wanted = [];

        foreach (['parent', 'author', 'featured', 'on', 'menu', 'object'] as $key) {
            $sourceId = $refs[$key] ?? null;

            if (! is_string($sourceId) || $sourceId === '') {
                continue;
            }

            foreach ($this->kindsFor($list, $key, is_string($refs['object_kind'] ?? null) ? (string) $refs['object_kind'] : null) as $kind) {
                $wanted[] = [$kind, $sourceId];
            }
        }

        foreach (is_array($refs['terms'] ?? null) ? $refs['terms'] : [] as $termId) {
            if (is_string($termId) && $termId !== '') {
                $wanted[] = [ImportKind::TERM, $termId];
            }
        }

        return $wanted;
    }

    /**
     * @return list<string>
     */
    private function kindsFor(string $list, string $key, ?string $objectKind): array
    {
        return match ([$list, $key]) {
            [ItemRows::CONTENT, 'parent'], [ItemRows::MEDIA, 'parent'] => [ImportKind::POST, ImportKind::PAGE],
            [ItemRows::CONTENT, 'author'], [ItemRows::MEDIA, 'author'], [ItemRows::COMMENTS, 'author'] => [ImportKind::PERSON],
            [ItemRows::CONTENT, 'featured'] => [ImportKind::MEDIA],
            [ItemRows::OTHER_TERMS, 'parent'], [ItemRows::TERMS, 'parent'] => [ImportKind::TERM],
            [ItemRows::COMMENTS, 'parent'] => [ImportKind::COMMENT],
            [ItemRows::COMMENTS, 'on'] => [ImportKind::POST, ImportKind::PAGE],
            [ItemRows::MENU_ITEMS, 'parent'] => [ImportKind::MENU_ITEM],
            [ItemRows::MENU_ITEMS, 'menu'] => [ImportKind::MENU],
            [ItemRows::MENU_ITEMS, 'object'] => $objectKind === 'term'
                ? [ImportKind::TERM]
                : ($objectKind === 'content' ? [ImportKind::POST, ImportKind::PAGE] : [ImportKind::POST, ImportKind::PAGE, ImportKind::TERM]),
            default => [],
        };
    }

    /**
     * @param  array<string, array<string, true>>  $wanted
     *
     * @return array<string, int>
     */
    private function lookup(string $address, string $reader, array $wanted): array
    {
        $ids = [];

        foreach ($wanted as $kind => $sourceIds) {
            foreach (array_chunk(array_keys($sourceIds), self::CHUNK) as $chunk) {
                foreach (DB::table('import_reader_ids')
                    ->where('address', $address)
                    ->where('reader', $reader)
                    ->where('object_type', $kind)
                    ->whereIn('source_id', $chunk)
                    ->get(['object_type', 'source_id', 'object_id']) as $row) {
                    $ids[ReaderRows::key((string) $row->object_type, (string) $row->source_id)] = (int) $row->object_id;
                }
            }
        }

        return $ids;
    }

    /**
     * The payload with engine IDs filled from its reader_refs where known.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $ids
     *
     * @return array<string, mixed>
     */
    private function filled(string $list, array $payload, array $ids, string $fileId): array
    {
        $refs = is_array($payload['reader_refs'] ?? null) ? $payload['reader_refs'] : [];

        if ($list === ItemRows::CONTENT) {
            $parent = $this->contentId($refs['parent'] ?? null, $ids);
            $author = $this->idOf(ImportKind::PERSON, $refs['author'] ?? null, $ids);
            $featured = $this->idOf(ImportKind::MEDIA, $refs['featured'] ?? null, $ids);
            $terms = $this->termsOf($fileId, is_array($refs['terms'] ?? null) ? $refs['terms'] : [], $ids, is_array($payload['terms'] ?? null) ? $payload['terms'] : []);

            if ($parent !== null) {
                $payload['parent'] = $parent;
            }

            if ($author !== null) {
                $payload['author'] = $author;
            }

            if ($featured !== null) {
                $payload['featured_media'] = $featured;
            }

            if ($terms !== []) {
                $payload['terms'] = $terms;
            }
        } elseif ($list === ItemRows::MEDIA) {
            $parent = $this->contentId($refs['parent'] ?? null, $ids);
            $author = $this->idOf(ImportKind::PERSON, $refs['author'] ?? null, $ids);

            if ($parent !== null) {
                $payload['parent'] = $parent;
            }

            if ($author !== null) {
                $payload['author'] = $author;
            }
        } elseif ($list === ItemRows::COMMENTS) {
            $post = $this->contentId($refs['on'] ?? null, $ids);
            $parent = $this->idOf(ImportKind::COMMENT, $refs['parent'] ?? null, $ids);
            $author = $this->idOf(ImportKind::PERSON, $refs['author'] ?? null, $ids);

            if ($post !== null) {
                $payload['post_id'] = $post;
            }

            if ($parent !== null) {
                $payload['parent'] = $parent;
            }

            if ($author !== null) {
                $payload['user_id'] = $author;
            }
        } elseif ($list === ItemRows::MENU_ITEMS) {
            $menu = $this->idOf(ImportKind::MENU, $refs['menu'] ?? null, $ids);
            $parent = $this->idOf(ImportKind::MENU_ITEM, $refs['parent'] ?? null, $ids);
            $objectKind = is_string($refs['object_kind'] ?? null) ? (string) $refs['object_kind'] : null;
            $object = $objectKind === 'term'
                ? $this->idOf(ImportKind::TERM, $refs['object'] ?? null, $ids)
                : $this->contentId($refs['object'] ?? null, $ids);

            if ($menu !== null) {
                $payload['menu'] = $menu;
            }

            if ($parent !== null) {
                $payload['parent'] = $parent;
            }

            if ($object !== null) {
                $payload['object_id'] = $object;
            }
        } elseif (in_array($list, [ItemRows::TERMS, ItemRows::OTHER_TERMS], true)) {
            $parent = $this->idOf(ImportKind::TERM, $refs['parent'] ?? null, $ids);

            if ($parent !== null) {
                $payload['parent'] = $parent;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, int>  $ids
     */
    private function contentId(mixed $sourceId, array $ids): ?int
    {
        if (! is_string($sourceId) || $sourceId === '') {
            return null;
        }

        return $ids[ReaderRows::key(ImportKind::POST, $sourceId)]
            ?? $ids[ReaderRows::key(ImportKind::PAGE, $sourceId)]
            ?? null;
    }

    /**
     * @param  array<string, int>  $ids
     */
    private function idOf(string $kind, mixed $sourceId, array $ids): ?int
    {
        if (! is_string($sourceId) || $sourceId === '') {
            return null;
        }

        return $ids[ReaderRows::key($kind, $sourceId)] ?? null;
    }

    /**
     * Terms already on the payload, plus any reader_refs terms whose rows name a taxonomy.
     *
     * @param  list<mixed>  $termIds
     * @param  array<string, int>  $ids
     * @param  array<string, mixed>  $terms
     *
     * @return array<string, list<int>>
     */
    private function termsOf(string $fileId, array $termIds, array $ids, array $terms): array
    {
        $engineIds = [];

        foreach ($termIds as $termId) {
            if (! is_string($termId) || $termId === '') {
                continue;
            }

            $engineId = $ids[ReaderRows::key(ImportKind::TERM, $termId)] ?? null;

            if ($engineId !== null) {
                $engineIds[] = $engineId;
            }
        }

        if ($engineIds === []) {
            return $terms;
        }

        $byId = [];

        foreach (DB::table('import_file_rows')
            ->where('file_id', $fileId)
            ->whereIn('list', [ItemRows::TERMS, ItemRows::OTHER_TERMS])
            ->whereIn('object_id', $engineIds)
            ->get(['object_id', 'payload']) as $row) {
            $body = is_string($row->payload) ? json_decode($row->payload, true) : null;
            $taxonomy = is_array($body) ? (string) ($body['taxonomy'] ?? '') : '';

            if ($taxonomy !== '' && $taxonomy !== 'nav_menu') {
                $byId[(int) $row->object_id] = $taxonomy;
            }
        }

        foreach ($engineIds as $engineId) {
            $taxonomy = $byId[$engineId] ?? null;

            if ($taxonomy === null) {
                continue;
            }

            $held = is_array($terms[$taxonomy] ?? null) ? $terms[$taxonomy] : [];

            if (! in_array($engineId, $held, true)) {
                $held[] = $engineId;
            }

            $terms[$taxonomy] = $held;
        }

        return $terms;
    }
}

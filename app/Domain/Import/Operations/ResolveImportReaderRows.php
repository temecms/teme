<?php

/**
 * Fills a reader file's later-page references from the engine IDs already
 * assigned: parents, authors, featured pictures, terms, posts, menus and
 * menu targets, by joining each row's stored source IDs to import_reader_ids.
 * Pages on (list, object_id), resumes under a deadline, and counts gap notes
 * per chunk. Who may import may call it; the reader is not opened again.
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Teme\Contracts\Import\ImportKind;
use Teme\Contracts\Import\ImportMenuItemType;
use Teme\Contracts\Operations\OperationInput;

final class ResolveImportReaderRows extends BaseOperation
{
    use AuthorizesImports;

    private const int CHUNK = 500;

    private const array NOTE_SENTENCES = [
        'comment' => 'Comments on posts that aren’t in this file weren’t brought.',
        'menu-item' => 'Menu items in menus that aren’t in this file weren’t brought.',
        'term' => 'Terms of posts and pages that aren’t in this file weren’t brought, so those posts aren’t filed under them.',
        'featured-image' => 'Featured pictures that aren’t in this file weren’t brought, so those posts have none for now.',
        'menu-target' => 'Menu items that point at a post, page or term that isn’t in this file were brought with nothing to point at.',
        'parent' => 'Items whose parent isn’t in this file were placed at the top, with no parent.',
    ];

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
     * How far the resolve got: rewritten count, whether every row was visited,
     * the keyset to resume from, and gap notes counted on the chunks this call saw.
     *
     * @return array{rewritten: int, done: bool, afterList: ?string, afterObjectId: ?int, notes: array<string, array{count: int, examples: list<string>, sentence: string}>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ResolveImportReaderRowsInput $input */
        $rewritten = 0;
        $afterList = $input->afterList;
        $afterId = $input->afterObjectId;
        $chunk = $this->chunkSize();
        $notes = [];
        $chunks = 0;
        $done = true;

        do {
            if ($chunks > 0 && $this->pastDeadline($input->deadline)) {
                $done = false;

                break;
            }

            $query = DB::table('import_file_rows')
                ->where('file_id', $input->fileId)
                ->whereRaw('jsonb_exists(payload, ?)', ['reader_refs'])
                ->orderBy('list')
                ->orderBy('object_id')
                ->limit($chunk);

            if ($afterList !== null) {
                $query->where(function ($inner) use ($afterList, $afterId): void {
                    $inner->where('list', '>', $afterList)
                        ->orWhere(function ($same) use ($afterList, $afterId): void {
                            $same->where('list', $afterList)->where('object_id', '>', $afterId);
                        });
                });
            }

            $rows = $query->get(['list', 'object_id', 'payload']);

            if ($rows->isEmpty()) {
                break;
            }

            $chunks++;
            $wanted = [];
            $last = $rows->last();
            $afterList = (string) $last->list;
            $afterId = (int) $last->object_id;

            foreach ($rows as $row) {
                $payload = is_string($row->payload) ? json_decode($row->payload, true) : null;
                $refs = is_array($payload) && is_array($payload['reader_refs'] ?? null) ? $payload['reader_refs'] : [];

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
                $this->noteRow($notes, (string) $row->list, $next);

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

            foreach (array_chunk($writes, $chunk) as $batch) {
                DB::table('import_file_rows')->upsert($batch, ['file_id', 'list', 'object_id'], ['payload']);
                $rewritten += count($batch);
            }

            if ($rows->count() < $chunk) {
                break;
            }

            if ($this->pastDeadline($input->deadline)) {
                $done = false;

                break;
            }
        } while (true);

        return [
            'rewritten' => $rewritten,
            'done' => $done,
            'afterList' => $done ? null : $afterList,
            'afterObjectId' => $done ? null : $afterId,
            'notes' => $notes,
        ];
    }

    /**
     * How many rows one resolve chunk loads. Tests may set
     * `teme.import.reader_resolve_chunk` so a short file spans several chunks.
     */
    private function chunkSize(): int
    {
        $size = (int) config('teme.import.reader_resolve_chunk', self::CHUNK);

        return $size > 0 ? $size : self::CHUNK;
    }

    /**
     * Whether the chunk's time is up. Asked again after each chunk, since
     * each takes time: never a value to remember between calls.
     *
     * @phpstan-impure
     */
    private function pastDeadline(?CarbonImmutable $deadline): bool
    {
        return $deadline !== null && CarbonImmutable::now()->greaterThanOrEqualTo($deadline);
    }

    /**
     * Adds one row's unresolved gaps to the running note counts.
     *
     * @param  array<string, array{count: int, examples: list<string>, sentence: string}>  $notes
     * @param  array<string, mixed>  $payload
     */
    private function noteRow(array &$notes, string $list, array $payload): void
    {
        $refs = is_array($payload['reader_refs'] ?? null) ? $payload['reader_refs'] : [];
        $example = is_string($payload['source_id'] ?? null) && $payload['source_id'] !== ''
            ? (string) $payload['source_id']
            : (string) ($payload['id'] ?? '');

        if ($list === ItemRows::COMMENTS && ($payload['post_id'] ?? null) === null && is_string($refs['on'] ?? null)) {
            $this->addNote($notes, 'comment', $example);
        }

        if ($list === ItemRows::MENU_ITEMS && (int) ($payload['menu'] ?? 0) === 0 && is_string($refs['menu'] ?? null)) {
            $this->addNote($notes, 'menu-item', $example);
        }

        if ($list === ItemRows::CONTENT) {
            if (is_array($refs['terms'] ?? null) && count($refs['terms']) > 0) {
                $held = 0;

                foreach (is_array($payload['terms'] ?? null) ? $payload['terms'] : [] as $ids) {
                    $held += is_array($ids) ? count($ids) : 0;
                }

                if ($held < count($refs['terms'])) {
                    $this->addNote($notes, 'term', $example);
                }
            }

            if (is_string($refs['featured'] ?? null) && ($payload['featured_media'] ?? null) === null) {
                $this->addNote($notes, 'featured-image', $example);
            }
        }

        if ($list === ItemRows::MENU_ITEMS
            && in_array($refs['object_kind'] ?? null, [ImportMenuItemType::CONTENT, ImportMenuItemType::TERM], true)
            && (int) ($payload['object_id'] ?? 0) === 0
            && (int) ($payload['menu'] ?? 0) !== 0) {
            $this->addNote($notes, 'menu-target', $example);
        }

        if (is_string($refs['parent'] ?? null) && (int) ($payload['parent'] ?? 0) === 0
            && ! ($list === ItemRows::COMMENTS && ($payload['post_id'] ?? null) === null)
            && ! ($list === ItemRows::MENU_ITEMS && (int) ($payload['menu'] ?? 0) === 0)) {
            $this->addNote($notes, 'parent', $example);
        }
    }

    /**
     * @param  array<string, array{count: int, examples: list<string>, sentence: string}>  $notes
     */
    private function addNote(array &$notes, string $kind, string $example): void
    {
        $held = $notes[$kind] ?? ['count' => 0, 'examples' => [], 'sentence' => self::NOTE_SENTENCES[$kind]];
        $examples = $held['examples'];

        if ($example !== '' && count($examples) < 5 && ! in_array($example, $examples, true)) {
            $examples[] = $example;
        }

        $notes[$kind] = [
            'count' => (int) $held['count'] + 1,
            'examples' => $examples,
            'sentence' => $held['sentence'],
        ];
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

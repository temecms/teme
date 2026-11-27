<?php

/**
 * A small import reader for tests: it reads a JSON file of a few posts, a
 * page, a term and a person, and proves the import socket carries them.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

use JsonException;
use Teme\Contracts\Import\ImportItem;
use Teme\Contracts\Import\ImportKind;
use Teme\Contracts\Import\ImportPage;
use Teme\Contracts\Import\ImportProbe;
use Teme\Contracts\Import\ImportReader;
use Teme\Contracts\Import\ImportReaderRefused;

/**
 * Reads the file `{"system", "version", "site", "languages", "items"}`. Each
 * item names its `kind` and `id`; the other keys are the kind's fields, as
 * the guide names them. A cursor is the count of items read so far.
 *
 * @since next
 */
final class FakeImportReader implements ImportReader
{
    /** The statuses a fake item may carry, which are Teme's. */
    private const array STATUSES = ['published', 'draft', 'private', 'scheduled', 'trashed', 'spam'];

    /**
     * @since next
     */
    public function probe(string $path): ImportProbe
    {
        $file = $this->file($path);
        $counts = [];

        foreach ($file['items'] as $item) {
            $counts[$item->kind] = ($counts[$item->kind] ?? 0) + 1;
        }

        return new ImportProbe(
            system: $file['system'],
            version: isset($file['version']) ? (string) $file['version'] : null,
            siteAddress: isset($file['site']) ? (string) $file['site'] : null,
            languages: array_values((array) ($file['languages'] ?? [])),
            counts: $counts,
        );
    }

    /**
     * @since next
     */
    public function page(string $path, ?string $cursor, int $limit): ImportPage
    {
        $items = $this->file($path)['items'];
        $start = $cursor === null ? 0 : (int) $cursor;
        $page = array_slice($items, $start, $limit);
        $next = $start + count($page);

        return new ImportPage($page, $next < count($items) ? (string) $next : null);
    }

    /**
     * @since next
     */
    public function fetch(string $path, string $id): ImportItem
    {
        foreach ($this->file($path)['items'] as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        throw ImportReaderRefused::because('This file has no item with that ID.');
    }

    /**
     * The file's items in the neutral shape, read on each call.
     *
     * @return array{system: string, version: mixed, site: mixed, languages: mixed, items: list<ImportItem>}
     *
     * @throws ImportReaderRefused when the file isn't a fake export
     */
    private function file(string $path): array
    {
        try {
            $file = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ImportReaderRefused::because('This file isn’t a fake export: it isn’t JSON.');
        }

        if (! is_array($file) || ! is_string($file['system'] ?? null) || ! is_array($file['items'] ?? null)) {
            throw ImportReaderRefused::because('This file isn’t a fake export: it has no system or items.');
        }

        $items = [];

        foreach (array_values($file['items']) as $raw) {
            $items[] = $this->item(is_array($raw) ? $raw : []);
        }

        return ['system' => $file['system'], 'version' => $file['version'] ?? null, 'site' => $file['site'] ?? null, 'languages' => $file['languages'] ?? [], 'items' => $items];
    }

    /**
     * One item of the file, in the neutral shape.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws ImportReaderRefused when its kind, ID or status isn't one Teme knows
     */
    private function item(array $raw): ImportItem
    {
        $kind = (string) ($raw['kind'] ?? '');
        $id = (string) ($raw['id'] ?? '');
        $status = (string) ($raw['status'] ?? 'published');

        if (! in_array($kind, ImportKind::all(), true) || $id === '') {
            throw ImportReaderRefused::because('An item of this file has no known kind or no ID.');
        }

        if (! in_array($status, self::STATUSES, true)) {
            throw ImportReaderRefused::because('An item of this file has a status Teme doesn’t know.');
        }

        $fields = match ($kind) {
            ImportKind::PERSON => ['login' => $raw['login'] ?? '', 'email' => $raw['email'] ?? '', 'displayName' => $raw['displayName'] ?? ''],
            ImportKind::TERM => ['taxonomy' => $raw['taxonomy'] ?? 'category', 'name' => $raw['name'] ?? '', 'description' => $raw['description'] ?? ''],
            ImportKind::MEDIA => ['url' => $raw['url'] ?? '', 'mime' => $raw['mime'] ?? '', 'alt' => $raw['alt'] ?? ''],
            ImportKind::COMMENT => ['authorName' => $raw['authorName'] ?? '', 'authorEmail' => $raw['authorEmail'] ?? '', 'authorUrl' => $raw['authorUrl'] ?? '', 'authorIp' => $raw['authorIp'] ?? ''],
            ImportKind::MENU => ['slug' => $raw['slug'] ?? ''],
            ImportKind::MENU_ITEM => ['url' => $raw['url'] ?? '', 'object' => $raw['object'] ?? ''],
            default => [],
        };

        return new ImportItem(
            kind: $kind,
            id: $id,
            title: (string) ($raw['title'] ?? $raw['name'] ?? $raw['displayName'] ?? ''),
            slug: (string) ($raw['slug'] ?? ''),
            status: $status,
            publishedAt: isset($raw['publishedAt']) ? (string) $raw['publishedAt'] : null,
            modifiedAt: isset($raw['modifiedAt']) ? (string) $raw['modifiedAt'] : null,
            oldAddress: isset($raw['oldAddress']) ? (string) $raw['oldAddress'] : null,
            content: (string) ($raw['content'] ?? ''),
            format: (string) ($raw['format'] ?? 'html'),
            parentId: isset($raw['parentId']) ? (string) $raw['parentId'] : null,
            authorId: isset($raw['authorId']) ? (string) $raw['authorId'] : null,
            termIds: array_map(strval(...), (array) ($raw['termIds'] ?? [])),
            fields: $fields,
            postId: isset($raw['postId']) ? (string) $raw['postId'] : null,
            menuId: isset($raw['menuId']) ? (string) $raw['menuId'] : null,
            itemType: isset($raw['itemType']) ? (string) $raw['itemType'] : null,
            objectId: isset($raw['objectId']) ? (string) $raw['objectId'] : null,
            order: (int) ($raw['order'] ?? 0),
            placement: isset($raw['placement']) ? (string) $raw['placement'] : null,
        );
    }
}

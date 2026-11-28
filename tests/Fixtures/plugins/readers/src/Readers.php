<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Readers;

use Carbon\CarbonImmutable;
use RuntimeException;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Import\HasImportReader;
use Teme\Contracts\Import\ImportItem;
use Teme\Contracts\Import\ImportPage;
use Teme\Contracts\Import\ImportProbe;
use Teme\Contracts\Import\ImportReader;
use Teme\Contracts\Plugins\Plugin;
use Teme\Testing\FakeImportReader;

/**
 * A fixture plugin with one import reader, which reads the fake export the
 * testing kit defines.
 */
final class Readers implements HasImportReader, Plugin
{
    public function register(Hooks $hooks): void {}

    public function importReader(string $readerId): ImportReader
    {
        return match ($readerId) {
            'stuck' => new StuckImportReader,
            'slow' => new SlowImportReader,
            'raw' => new RawImportReader,
            default => new FakeImportReader,
        };
    }
}

/**
 * Reads the fake export, but hands back the same next cursor for every page,
 * as a reader with a bug would.
 */
final class StuckImportReader implements ImportReader
{
    private FakeImportReader $fake;

    public function __construct()
    {
        $this->fake = new FakeImportReader;
    }

    public function probe(string $path): ImportProbe
    {
        return $this->fake->probe($path);
    }

    public function page(string $path, ?string $cursor, int $limit): ImportPage
    {
        return new ImportPage([], '1');
    }

    public function fetch(string $path, string $id): ImportItem
    {
        return $this->fake->fetch($path, $id);
    }
}

/**
 * Reads the fake export, and takes a minute over each page, as a slow reader would.
 */
final class SlowImportReader implements ImportReader
{
    private FakeImportReader $fake;

    public function __construct()
    {
        $this->fake = new FakeImportReader;
    }

    public function probe(string $path): ImportProbe
    {
        return $this->fake->probe($path);
    }

    public function page(string $path, ?string $cursor, int $limit): ImportPage
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());

        return $this->fake->page($path, $cursor, $limit);
    }

    public function fetch(string $path, string $id): ImportItem
    {
        return $this->fake->fetch($path, $id);
    }
}

/**
 * Reads the same JSON shape as the fake export but hands each item over exactly as written, with no
 * check of its words, so a test can give core a status or a parent that no real reader should.
 */
final class RawImportReader implements ImportReader
{
    public function probe(string $path): ImportProbe
    {
        $file = $this->file($path);

        return new ImportProbe(system: 'Raw export', version: '1', siteAddress: (string) ($file['site'] ?? ''), languages: ['en'], counts: []);
    }

    public function page(string $path, ?string $cursor, int $limit): ImportPage
    {
        $items = [];

        foreach ($this->file($path)['items'] ?? [] as $raw) {
            $items[] = new ImportItem(
                kind: (string) $raw['kind'],
                id: (string) $raw['id'],
                title: (string) ($raw['title'] ?? ''),
                slug: (string) ($raw['slug'] ?? ''),
                status: (string) ($raw['status'] ?? 'published'),
                content: (string) ($raw['content'] ?? ''),
                parentId: isset($raw['parentId']) ? (string) $raw['parentId'] : null,
                postId: isset($raw['postId']) ? (string) $raw['postId'] : null,
                menuId: isset($raw['menuId']) ? (string) $raw['menuId'] : null,
                itemType: isset($raw['itemType']) ? (string) $raw['itemType'] : null,
                fields: $raw,
            );
        }

        return new ImportPage($items, null);
    }

    public function fetch(string $path, string $id): ImportItem
    {
        foreach ($this->page($path, null, 1000)->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        throw new RuntimeException('No such item.');
    }

    /**
     * @return array<string, mixed>
     */
    private function file(string $path): array
    {
        return (array) json_decode((string) file_get_contents($path), true);
    }
}

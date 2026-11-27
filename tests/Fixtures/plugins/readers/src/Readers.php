<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Readers;

use Carbon\Carbon;
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
        Carbon::setTestNow(Carbon::now()->addMinute());

        return $this->fake->page($path, $cursor, $limit);
    }

    public function fetch(string $path, string $id): ImportItem
    {
        return $this->fake->fetch($path, $id);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Readers;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Import\HasImportReader;
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
        return new FakeImportReader;
    }
}

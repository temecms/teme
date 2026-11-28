<?php

/**
 * A plugin granted import.read whose manifest declares import readers:
 * hands over the reader for each reader ID it declares.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * A plugin that reads another system's export file. Its manifest declares
 * each reader by ID (contributes.importReaders), and this method hands over
 * the reader for an ID.
 *
 * @since 1.8.0
 */
interface HasImportReader
{
    /**
     * The reader for one of the IDs the manifest declares.
     *
     * @since 1.8.0
     */
    public function importReader(string $readerId): ImportReader;
}

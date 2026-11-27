<?php

/**
 * Reads an export file from another system for Teme's import engine: it
 * probes the file, lists its items a page at a time and fetches one. It
 * only reads; core does every write, as the person who started the import.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * A reader for one kind of export file. Each path is the uploaded file's
 * own path; a reader only reads it, and never writes to it or to the site,
 * nor fetches anything from the network to answer a call.
 *
 * @since next
 */
interface ImportReader
{
    /**
     * What the file is: its system, version, languages and counts.
     *
     * @throws ImportReaderRefused when the file isn't one this reader reads
     *
     * @since next
     */
    public function probe(string $path): ImportProbe;

    /**
     * A page of the file's items after the cursor, or from the start for
     * null. Returns at most the limit, in an order that stays the same
     * between calls.
     *
     * @throws ImportReaderRefused when the file can't be read from there
     *
     * @since next
     */
    public function page(string $path, ?string $cursor, int $limit): ImportPage;

    /**
     * One item by its source ID.
     *
     * @throws ImportReaderRefused when the file holds no such item
     *
     * @since next
     */
    public function fetch(string $path, string $id): ImportItem;
}

<?php

/**
 * Chooses what an import reads: the export file a run names, or the site's
 * companion; for a site's plan, the way it came last.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Source;

use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Queries\ImportFiles;
use App\Domain\Support\Network\AddressRefused;

/**
 * One site, whichever way it came (docs/architecture/import.md): a run with
 * a file reads the file, a run without one the companion. A site's plan and
 * its old addresses read its newest ready file when the site was never
 * connected live, or when the file came after the last connection.
 */
final readonly class SourceReaders
{
    public function __construct(private CompanionClients $clients, private ImportFiles $files) {}

    /**
     * The reader of one run: its file's, or the companion's.
     *
     * @throws AddressRefused when the companion's address now resolves somewhere Teme won't go
     */
    public function for(ImportRun $run): SourceReader
    {
        if ($run->file_id !== null) {
            return new FileReader((string) $run->file_id, (string) $run->source_id, $run->choices);
        }

        return $this->clients->for($run->source);
    }

    /**
     * The reader of a site before a run: its file's, when the site reads
     * from one now, else the companion's.
     *
     * @param  array<string, mixed>  $choices
     *
     * @throws AddressRefused when the companion's address now resolves somewhere Teme won't go
     */
    public function forSource(ImportSource $source, array $choices = []): SourceReader
    {
        $file = $this->fileOf($source);

        return $file === null ? $this->clients->for($source) : new FileReader((string) $file->id, (string) $source->id, $choices);
    }

    /**
     * The file a site reads from now: its newest ready file, when the site
     * has never been connected live or the file came after its last
     * connection; null when it reads from the companion.
     */
    public function fileOf(ImportSource $source): ?ImportFile
    {
        $file = $this->files->current((string) $source->id);

        if ($file === null) {
            return null;
        }

        return $source->api_root === null || $source->probed_at === null || $file->created_at->greaterThan($source->probed_at) ? $file : null;
    }
}

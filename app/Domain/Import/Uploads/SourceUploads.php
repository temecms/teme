<?php

/**
 * Finds a picture for an import in the copy of its old site's uploads folder
 * when the site has one. A picture named by a WordPress attachment's own file
 * is looked up by that path, whatever host serves it (a CDN or an offloaded
 * upload folder). An address from elsewhere is looked up only when its host is
 * the site's own or one of its other hosts. A zip is opened once per import and
 * held for its life, not reopened for each picture. Null when there is no copy,
 * or it lacks the picture.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Uploads;

use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Models\ImportUpload;
use App\Domain\Media\Downloads\DownloadedFile;
use RuntimeException;
use ZipArchive;

final class SourceUploads
{
    /** @var array<string, ZipArchive> The zip archives opened for this import, by their path, held until it ends. */
    private array $archives = [];

    /**
     * The picture at this address from the site's copy, when its host is the
     * site's own or one of its other hosts; null otherwise.
     *
     * @throws RuntimeException when the temporary file can't be made
     */
    public function download(string $sourceId, string $address, int $maxBytes): ?DownloadedFile
    {
        $copy = $this->copyOf($sourceId);

        if ($copy === null || ! $this->onTheSite($sourceId, $address)) {
            return null;
        }

        return $copy->download($address, $maxBytes, sys_get_temp_dir(), $this->archiveOf($copy));
    }

    /**
     * The picture an attachment names by its own file, under the uploads folder,
     * from the site's copy whatever host the address is on; null when the copy
     * lacks it.
     *
     * @throws RuntimeException when the temporary file can't be made
     */
    public function downloadFile(string $sourceId, string $relative, string $address, int $maxBytes): ?DownloadedFile
    {
        $copy = $this->copyOf($sourceId);

        if ($copy === null) {
            return null;
        }

        return $copy->downloadRelative($relative, $address, $maxBytes, sys_get_temp_dir(), $this->archiveOf($copy));
    }

    public function __destruct()
    {
        foreach ($this->archives as $archive) {
            $archive->close();
        }
    }

    private function copyOf(string $sourceId): ?UploadsCopy
    {
        $upload = ImportUpload::query()->where('source_id', $sourceId)->where('state', 'ready')->first();

        return $upload === null ? null : new UploadsCopy($upload->kind, $upload->path, (string) $upload->prefix);
    }

    /**
     * The zip of a copy, opened once; null for a folder copy.
     */
    private function archiveOf(UploadsCopy $copy): ?ZipArchive
    {
        if ($copy->kind !== UploadsCopy::ZIP) {
            return null;
        }

        if (! isset($this->archives[$copy->path])) {
            $zip = new ZipArchive;

            if ($zip->open($copy->path, ZipArchive::RDONLY) !== true) {
                return null;
            }

            $this->archives[$copy->path] = $zip;
        }

        return $this->archives[$copy->path];
    }

    /**
     * Whether the address's host is the site's own or one of its other hosts.
     */
    private function onTheSite(string $sourceId, string $address): bool
    {
        $host = strtolower((string) parse_url($address, PHP_URL_HOST));
        $source = ImportSource::query()->whereKey($sourceId)->first();

        if ($host === '' || $source === null) {
            return false;
        }

        $hosts = [strtolower((string) parse_url((string) $source->address, PHP_URL_HOST)), ...array_map(strtolower(...), $source->other_hosts ?? [])];

        return in_array($host, $hosts, true);
    }
}

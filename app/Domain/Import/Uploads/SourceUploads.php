<?php

/**
 * Finds a picture for an import in the copy of its old site's uploads folder,
 * when the site has one: only for an address on the site itself or on one of
 * its other hosts, so a picture from another site's host is never taken from
 * this copy. Null when there is no copy, or it lacks the picture.
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

final readonly class SourceUploads
{
    /**
     * The picture at this address from the site's copy, in a temporary file
     * the caller releases; null when the site has no copy, the address isn't
     * on the site, or the copy lacks the picture.
     *
     * @throws RuntimeException when the temporary file can't be made
     */
    public function download(string $sourceId, string $address, int $maxBytes): ?DownloadedFile
    {
        $upload = ImportUpload::query()->where('source_id', $sourceId)->first();

        if ($upload === null || ! $this->onTheSite($sourceId, $address)) {
            return null;
        }

        return (new UploadsCopy($upload->kind, $upload->path))->download($address, $maxBytes, sys_get_temp_dir());
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

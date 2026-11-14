<?php

declare(strict_types=1);

namespace App\Domain\Export;

/**
 * What an export wrote: its folder, each file with its lines, bytes and
 * SHA-256, and the media originals copied, missing or changed.
 */
final readonly class ExportedSite
{
    /**
     * @param  list<ExportedFile>  $files
     */
    public function __construct(public string $folder, public array $files, public MediaTally $media) {}

    /** How many lines the kind wrote; 0 for a kind it didn't write. */
    public function lines(string $kind): int
    {
        foreach ($this->files as $file) {
            if ($file->kind === $kind) {
                return $file->lines;
            }
        }

        return 0;
    }
}

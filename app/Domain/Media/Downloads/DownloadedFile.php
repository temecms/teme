<?php

declare(strict_types=1);

namespace App\Domain\Media\Downloads;

/**
 * A file the downloader fetched, waiting in a temporary file: its name, its
 * type and kind as its bytes say, its size, and where it came from. Release
 * it when done, whatever happened; releasing it twice does nothing.
 */
final readonly class DownloadedFile
{
    public function __construct(
        public string $path,
        public string $name,
        public string $mimeType,
        public string $kind,
        public int $sizeBytes,
        public ?string $sourceUrl,
    ) {}

    public function release(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}

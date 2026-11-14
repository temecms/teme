<?php

declare(strict_types=1);

namespace App\Domain\Export;

/**
 * One media original copied into the export: its path inside it, its size and
 * its SHA-256, as copied.
 */
final readonly class CopiedOriginal
{
    /** @param string $path Relative to the export: media/<ID>.<ext> */
    public function __construct(public string $path, public int $bytes, public string $sha256) {}
}

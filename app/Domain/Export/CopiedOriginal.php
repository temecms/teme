<?php

/**
 * One media original copied into the export: its path inside it, its size and
 * its SHA-256, as copied.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

final readonly class CopiedOriginal
{
    /** @param string $path Relative to the export: media/<ID>.<ext> */
    public function __construct(public string $path, public int $bytes, public string $sha256) {}
}

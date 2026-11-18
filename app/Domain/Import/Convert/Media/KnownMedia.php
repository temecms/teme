<?php

/**
 * Media IDs already known, by attachment ID and by file path below the
 * uploads folder: what a test gives the converter, or a phase that read a
 * page's IDs at once.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Media;

final readonly class KnownMedia implements MediaTargets
{
    /**
     * @param  array<int, string>  $attachments  WordPress attachment ID to media ID
     * @param  array<string, string>  $files  the path below the uploads folder, lower-cased, to media ID
     */
    public function __construct(private array $attachments = [], private array $files = []) {}

    public function attachment(int $id): ?string
    {
        return $this->attachments[$id] ?? null;
    }

    public function file(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }
}

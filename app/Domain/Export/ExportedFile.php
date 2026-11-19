<?php

/**
 * One NDJSON file the export finished: its kind, name, version, the command's
 * noun, and how many lines and bytes, with their SHA-256.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

final readonly class ExportedFile
{
    public function __construct(public string $kind, public string $file, public int $version, public string $noun, public int $lines, public int $bytes, public string $sha256) {}

    /**
     * @return array{kind: string, file: string, version: int, lines: int, bytes: int, sha256: string}
     */
    public function toManifest(): array
    {
        return ['kind' => $this->kind, 'file' => $this->file, 'version' => $this->version, 'lines' => $this->lines, 'bytes' => $this->bytes, 'sha256' => $this->sha256];
    }
}

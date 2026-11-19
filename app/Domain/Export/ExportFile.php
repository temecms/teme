<?php

/**
 * One NDJSON file of an export, written line by line as its kind yields
 * them (I-365): nothing is held but the line being written, and the bytes
 * are counted and hashed as they go, so the manifest's SHA-256 is of
 * exactly what reached the file.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

use HashContext;
use LogicException;

final class ExportFile
{
    private HashContext $hash;

    private int $lines = 0;

    private int $bytes = 0;

    /**
     * @param  resource|null  $handle
     */
    public function __construct(private readonly ExportKind $kind, private readonly string $path, private mixed $handle)
    {
        $this->hash = hash_init('sha256');
    }

    /**
     * Writes one line: the kind and its version first, then the line's own
     * keys.
     *
     * @param  array<string, mixed>  $line
     */
    public function write(array $line): void
    {
        if (array_key_exists('kind', $line) || array_key_exists('version', $line)) {
            throw new LogicException("A line of {$this->kind->name()} can’t set its own kind or version.");
        }

        if (! is_resource($this->handle)) {
            throw new LogicException("{$this->path} is closed.");
        }

        $bytes = ExportJson::line(['kind' => $this->kind->name(), 'version' => $this->kind->version(), ...$line]);

        ExportFolder::writeAll($this->handle, $bytes, $this->path);
        hash_update($this->hash, $bytes);
        $this->bytes += strlen($bytes);
        $this->lines++;
    }

    /**
     * Flushes and closes the file; a failure of either is a failed write.
     */
    public function close(): ExportedFile
    {
        ExportFolder::closeWritten($this->handle, $this->path);
        $this->handle = null;

        return new ExportedFile(
            $this->kind->name(),
            basename($this->path),
            $this->kind->version(),
            $this->kind->noun(),
            $this->lines,
            $this->bytes,
            hash_final($this->hash),
        );
    }

    public function isOpen(): bool
    {
        return is_resource($this->handle);
    }

    /**
     * Closes the file without checking, so a discarded export can delete
     * it, on Windows too.
     */
    public function abandon(): void
    {
        if (is_resource($this->handle)) {
            @fclose($this->handle);
        }

        $this->handle = null;
    }
}

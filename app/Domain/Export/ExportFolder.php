<?php

/**
 * Where an export is written, and how (I-365): a folder readable by Teme's
 * user alone, made beside the target and renamed to it only when the
 * manifest is written, so the target exists only when complete. Every file
 * and folder it makes is recorded, so a failed export is removed by those
 * paths, deepest first, and never by listing a folder.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;
use LogicException;
use RuntimeException;
use stdClass;
use Throwable;

final class ExportFolder
{
    /** Bytes of an original read and written at a time. */
    private const int CHUNK = 1048576;

    /** @var list<string> Files made, in order. */
    private array $files = [];

    /** @var list<string> Folders made, in order: the staging folder first. */
    private array $folders = [];

    /** @var list<ExportFile> */
    private array $open = [];

    private bool $hasMediaFolder = false;

    private bool $settled = false;

    private function __construct(public readonly string $target, public readonly string $staging) {}

    /**
     * Makes the staging folder beside $target. Whether $target may be
     * written is the caller's to check first (ExportSite's validation);
     * finish() checks again.
     */
    public static function open(string $target): self
    {
        $target = rtrim(str_replace('\\', '/', $target), '/');
        $parent = rtrim(dirname($target), '/');
        $folder = new self($target, $parent.'/.'.basename($target).'.partial-'.Str::lower((string) Str::ulid()));

        $folder->makeFolder($folder->staging);

        // A fatal error, a timeout or a trapped signal's exit skips the
        // caller's catch; this still runs, and does nothing once settled.
        register_shutdown_function(function () use ($folder): void {
            $folder->discard();
        });

        return $folder;
    }

    public function ndjson(ExportKind $kind): ExportFile
    {
        if (preg_match('/^[a-z][a-z_]*$/', $kind->name()) !== 1) {
            throw new LogicException("An export kind is named in lowercase letters and underscores: [{$kind->name()}].");
        }

        $path = "{$this->staging}/{$kind->name()}.ndjson";
        $file = new ExportFile($kind, $path, $this->createFile($path));
        $this->open[] = $file;

        return $file;
    }

    /**
     * Copies one media item's original from its disk, by its recorded path,
     * into media/<ID>.<extension>, hashing it as it goes. Null when the disk
     * has no readable file there; a read that fails part way throws.
     */
    public function copyOriginal(string $id, string $extension, Filesystem $disk, string $path): ?CopiedOriginal
    {
        try {
            $source = $disk->readStream($path);
        } catch (UnableToReadFile) {
            $source = null;
        }

        if (! is_resource($source)) {
            return null;
        }

        $name = 'media/'.$id.'.'.self::extension($extension);
        $target = "{$this->staging}/{$name}";
        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            if (! $this->hasMediaFolder) {
                $this->makeFolder("{$this->staging}/media");
                $this->hasMediaFolder = true;
            }

            $handle = $this->createFile($target);

            try {
                while (! feof($source)) {
                    $chunk = fread($source, self::CHUNK);

                    if ($chunk === false) {
                        throw new RuntimeException("Teme couldn’t read the original {$path}.");
                    }

                    if ($chunk === '') {
                        break;
                    }

                    self::writeAll($handle, $chunk, $target);
                    hash_update($hash, $chunk);
                    $bytes += strlen($chunk);
                }

                self::closeWritten($handle, $target);
            } catch (Throwable $failed) {
                if (is_resource($handle)) {
                    @fclose($handle);
                }

                throw $failed;
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        return new CopiedOriginal($name, $bytes, hash_final($hash));
    }

    /**
     * Writes teme-types.json, the site's own types, taxonomies and fields,
     * exactly as given: the same file as `teme:types:export`, made the way
     * every file of the export is.
     *
     * @return array{file: string, bytes: int, sha256: string}
     */
    public function writeTypesFile(string $json): array
    {
        $path = "{$this->staging}/teme-types.json";
        $handle = $this->createFile($path);

        self::writeAll($handle, $json, $path);
        self::closeWritten($handle, $path);

        return ['file' => 'teme-types.json', 'bytes' => strlen($json), 'sha256' => hash('sha256', $json)];
    }

    /**
     * Writes manifest.json, pretty-printed, last of all.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function writeManifest(array $manifest): void
    {
        $path = "{$this->staging}/manifest.json";
        $handle = $this->createFile($path);

        self::writeAll($handle, json_encode($manifest === [] ? new stdClass : $manifest, ExportJson::FLAGS | JSON_PRETTY_PRINT, ExportJson::DEPTH)."\n", $path);
        self::closeWritten($handle, $path);
    }

    /**
     * Moves the finished export to its target. Checked first: on Linux a
     * rename replaces an empty folder, so a target made meanwhile would be
     * lost without a word.
     */
    public function finish(): void
    {
        foreach ($this->open as $file) {
            if ($file->isOpen()) {
                throw new LogicException('Every file of an export is closed before it is moved.');
            }
        }

        if (file_exists($this->target) || is_link($this->target)) {
            throw new RuntimeException("{$this->target} appeared while the export was being written, so the export was not moved there.");
        }

        if (! @rename($this->staging, $this->target)) {
            throw new RuntimeException("Teme couldn’t move the export to {$this->target}.");
        }

        $this->settled = true;
    }

    /**
     * Removes every file and folder this export made, by the paths it
     * recorded, files first, then folders deepest first. True when nothing
     * it made is left. Does nothing once finished or discarded.
     */
    public function discard(): bool
    {
        if ($this->settled) {
            return true;
        }

        $this->settled = true;
        $clean = true;

        foreach ($this->open as $file) {
            $file->abandon();
        }

        foreach (array_reverse($this->files) as $path) {
            if (is_file($path) && ! @unlink($path)) {
                $clean = false;
            }
        }

        foreach (array_reverse($this->folders) as $path) {
            if (is_dir($path) && ! @rmdir($path)) {
                $clean = false;
            }
        }

        return $clean;
    }

    /**
     * Writes every byte, however many calls it takes; a write that makes
     * no progress is a failure. Silenced: PHP warns on a failed write, and
     * the exception says it better.
     *
     * @param  resource  $handle
     */
    public static function writeAll(mixed $handle, string $bytes, string $path): void
    {
        $length = strlen($bytes);

        for ($done = 0; $done < $length; $done += $wrote) {
            $wrote = @fwrite($handle, $done === 0 ? $bytes : substr($bytes, $done));

            if ($wrote === false || $wrote === 0) {
                throw new RuntimeException("Teme couldn’t write {$path}: the disk may be full.");
            }
        }
    }

    /**
     * Flushes and closes a written file; either failing is a failed write,
     * since the last bytes may never have reached the disk.
     *
     * @param  resource|null  $handle
     */
    public static function closeWritten(mixed $handle, string $path): void
    {
        if (! is_resource($handle)) {
            throw new LogicException("{$path} is already closed.");
        }

        $flushed = @fflush($handle);
        $closed = @fclose($handle);

        if (! $flushed || ! $closed) {
            throw new RuntimeException("Teme couldn’t finish writing {$path}.");
        }
    }

    /**
     * A folder readable by Teme's user alone. mkdir() fails on one that
     * exists, so a folder is never shared with anything else.
     */
    private function makeFolder(string $path): void
    {
        $mask = umask(0077);

        try {
            $made = @mkdir($path, 0700);
        } finally {
            umask($mask);
        }

        if (! $made) {
            throw new RuntimeException("Teme couldn’t make the folder {$path}.");
        }

        $this->folders[] = $path;
        @chmod($path, 0700);
    }

    /**
     * A new file readable by Teme's user alone; `x` refuses one that exists.
     *
     * @return resource
     */
    private function createFile(string $path): mixed
    {
        $mask = umask(0077);

        try {
            $handle = @fopen($path, 'xb');
        } finally {
            umask($mask);
        }

        if ($handle === false) {
            throw new RuntimeException("Teme couldn’t write {$path}: the disk may be full.");
        }

        $this->files[] = $path;
        @chmod($path, 0600);

        return $handle;
    }

    /** An original's extension as a file name may hold it: lowercase letters and digits, else `bin`. */
    private static function extension(string $extension): string
    {
        $extension = strtolower($extension);

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : 'bin';
    }
}

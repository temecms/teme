<?php

/**
 * A copy of an old site's uploads folder, as a zip or a folder on this server:
 * it finds a picture by its path under the uploads folder and copies it into a
 * temporary file the caller releases. It never writes to, moves or deletes the
 * copy, reads a zip entry by entry without unpacking it, and reads only what
 * is a plain name, never a dot-file or a protected plugin's folder.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Uploads;

use App\Domain\Media\Downloads\DownloadedFile;
use App\Domain\Media\FileTypes;
use FilesystemIterator;
use finfo;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final readonly class UploadsCopy
{
    public const string ZIP = 'zip';

    public const string FOLDER = 'folder';

    /** The most entries a copy's zip may hold; more is refused when it is attached, as a central directory that size is read slowly. */
    public const int MOST_ENTRIES = 200000;

    /**
     * The folders of protected plugins (order forms, logs, uploads kept for
     * their customers). Their files are never brought in from a copy: they
     * are private to the plugin, and the site refuses them to readers too.
     */
    public const array PROTECTED_FOLDERS = ['woocommerce_uploads', 'wc-logs', 'gravity_forms', 'wpforms', 'ninja-forms'];

    /**
     * @param  string  $prefix  The folder, inside the copy, that holds the uploads: '' or a path ending in '/'.
     */
    public function __construct(
        public string $kind,
        public string $path,
        public string $prefix = '',
    ) {}

    /**
     * The picture at this address from the copy, copied into a temporary file in
     * the folder, or null when the copy lacks it, or holds one Teme won't take.
     *
     * @throws RuntimeException when the temporary file can't be made
     */
    public function download(string $address, int $maxBytes, string $folder, ?ZipArchive $open = null): ?DownloadedFile
    {
        $relative = self::relativePath($address);

        if ($relative === null || self::protectedReason($address) !== null) {
            return null;
        }

        return $this->downloadRelative($relative, $address, $maxBytes, $folder, $open);
    }

    /**
     * The picture at a path under the uploads folder (as an attachment's own file
     * names it) from the copy; null when the copy lacks it or Teme won't take it.
     *
     * @throws RuntimeException when the temporary file can't be made
     */
    public function downloadRelative(string $relative, string $address, int $maxBytes, string $folder, ?ZipArchive $open = null): ?DownloadedFile
    {
        $relative = self::safe($relative);

        if ($relative === null || self::protectedRelative($relative) !== null) {
            return null;
        }

        $sink = tempnam($folder, 'teme-copy-');

        if ($sink === false) {
            throw new RuntimeException('Teme couldn’t make a temporary file for a picture from the uploads copy.');
        }

        $size = $this->copyTo($relative, $sink, $maxBytes, $open);

        if ($size === null) {
            unlink($sink);

            return null;
        }

        $name = basename($relative);
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($sink);
        $type = FileTypes::for($mime, $name);

        if ($type === null) {
            unlink($sink);

            return null;
        }

        return new DownloadedFile($sink, $name, $mime, $type['kind'], $size, $address);
    }

    /**
     * The path under the uploads folder that an address names, checked so that
     * nothing outside the copy can be named; null when the address has no uploads
     * part or its path isn't safe. The first uploads/ in the path is the one that counts.
     */
    public static function relativePath(string $address): ?string
    {
        $path = parse_url($address, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $decoded = rawurldecode($path);
        $position = strpos($decoded, '/uploads/');

        if ($position === false) {
            return null;
        }

        return self::safe(substr($decoded, $position + strlen('/uploads/')));
    }

    /**
     * The relative path when every part of it is a plain name: no empty part, no
     * dot or dot-dot, no dot-file or dot-folder, no backslash, no NUL, no leading
     * slash; otherwise null.
     */
    public static function safe(string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
            return null;
        }

        foreach (explode('/', $relative) as $part) {
            // A dot-file or dot-folder (.env, .htaccess, .git) is never read from a copy.
            if ($part === '' || str_starts_with($part, '.')) {
                return null;
            }
        }

        return $relative;
    }

    /**
     * Whether an address names a protected plugin's folder, whose files are never
     * brought in from a copy (PROTECTED_FOLDERS), and why; null otherwise.
     */
    public static function protectedReason(string $address): ?string
    {
        $relative = self::relativePath($address);

        return $relative === null ? null : self::protectedRelative($relative);
    }

    /**
     * The reason a relative path names a protected plugin's folder, or null.
     */
    public static function protectedRelative(string $relative): ?string
    {
        $folder = self::protectedFolder($relative);

        return $folder === null ? null : 'It is in the folder of a protected plugin, '.$folder.', so Teme doesn’t bring it from a copy.';
    }

    /**
     * The protected plugin's folder a relative path is in, or null.
     */
    public static function protectedFolder(string $relative): ?string
    {
        foreach (explode('/', $relative) as $part) {
            if (in_array($part, self::PROTECTED_FOLDERS, true)) {
                return $part;
            }
        }

        return null;
    }

    /**
     * How many of the copy's files are in a protected plugin's folder, and in which folders: what the copy leaves out.
     *
     * @return array{files: int, folders: array<string, int>}
     */
    public function leftOut(): array
    {
        $folders = [];

        foreach ($this->filesUnderPrefix() as $relative) {
            $folder = self::protectedFolder($relative);

            if ($folder !== null) {
                $folders[$folder] = ($folders[$folder] ?? 0) + 1;
            }
        }

        ksort($folders);

        return ['files' => array_sum($folders), 'folders' => $folders];
    }

    /**
     * Each file of the copy under its prefix, by its path under that prefix, read from the folder or the zip's entries.
     *
     * @return Generator<int, string>
     */
    private function filesUnderPrefix(): Generator
    {
        if ($this->kind === self::FOLDER) {
            $root = rtrim($this->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->prefix;
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    yield substr($file->getPathname(), strlen($root));
                }
            }

            return;
        }

        $zip = new ZipArchive;

        if ($zip->open($this->path) !== true) {
            return;
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, $this->prefix) && ! str_ends_with($name, '/')) {
                yield substr($name, strlen($this->prefix));
            }
        }

        $zip->close();
    }

    /**
     * Whether a count of a copy's entries is over the most it may hold.
     */
    public static function holdsTooMany(int $entries): bool
    {
        return $entries > self::MOST_ENTRIES;
    }

    /**
     * Whether a real path is the application's own folder, inside it, or a folder
     * above it: such a path is never attached as an uploads copy, since it would
     * expose the application's own files (.env, its code, its logs).
     */
    public static function reachesApplication(string $real): bool
    {
        $real = rtrim($real, '/').'/';

        foreach ([base_path(), storage_path(), public_path()] as $root) {
            $root = (realpath($root) ?: $root).'/';

            if (str_starts_with($real, $root) || str_starts_with($root, $real)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The folder inside a zip that holds the uploads: the shortest prefix ending
     * in uploads/ of any file, or null when no file sits under an uploads folder.
     * Zips made from wp-content, from uploads itself, or from a folder above both
     * are found this way.
     */
    public static function prefixOfZip(ZipArchive $zip): ?string
    {
        $shortest = null;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_ends_with($name, '/') || ! str_contains($name, 'uploads/')) {
                continue;
            }

            $position = strpos($name, 'uploads/');
            $candidate = substr($name, 0, $position + strlen('uploads/'));

            if (($position === 0 || str_ends_with(substr($name, 0, $position), '/')) && ($shortest === null || strlen($candidate) < strlen($shortest))) {
                $shortest = $candidate;
            }
        }

        return $shortest;
    }

    /**
     * The folder inside a folder copy that holds the uploads: '' when the folder
     * is the uploads folder itself, 'uploads/' when it holds one, or null.
     */
    public static function prefixOfFolder(string $folder): ?string
    {
        if (is_dir($folder.DIRECTORY_SEPARATOR.'uploads')) {
            return 'uploads/';
        }

        return is_dir($folder) ? '' : null;
    }

    /**
     * Copies the entry or file for a relative path into the sink, stopping past the
     * limit; the bytes copied, or null when the copy lacks it.
     */
    private function copyTo(string $relative, string $sink, int $maxBytes, ?ZipArchive $open): ?int
    {
        return $this->kind === self::ZIP
            ? $this->copyZipEntry($relative, $sink, $maxBytes, $open)
            : $this->copyFile($relative, $sink, $maxBytes);
    }

    private function copyZipEntry(string $relative, string $sink, int $maxBytes, ?ZipArchive $open): ?int
    {
        $zip = $open ?? new ZipArchive;

        if ($open === null && $zip->open($this->path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $entry = $this->prefix.$relative;
            $index = $zip->locateName($entry);

            if ($index === false) {
                return null;
            }

            $stat = $zip->statIndex($index);

            if ($stat === false || (int) $stat['size'] > $maxBytes) {
                return null;
            }

            $stream = $zip->getStream($entry);

            if ($stream === false) {
                return null;
            }

            $out = fopen($sink, 'wb');

            if ($out === false) {
                fclose($stream);

                return null;
            }

            // The bytes are counted as they arrive, so an entry lying in its header about its size is still stopped.
            $copied = stream_copy_to_stream($stream, $out, $maxBytes + 1);
            fclose($stream);
            fclose($out);

            return $copied === false || $copied > $maxBytes ? null : (int) $copied;
        } finally {
            if ($open === null) {
                $zip->close();
            }
        }
    }

    private function copyFile(string $relative, string $sink, int $maxBytes): ?int
    {
        $base = realpath($this->path);
        $file = $base === false ? false : realpath($base.DIRECTORY_SEPARATOR.$this->prefix.$relative);

        // The file must sit inside the folder once symbolic links are followed.
        if ($base === false || $file === false || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            return null;
        }

        $size = filesize($file);

        if ($size === false || $size > $maxBytes || ! copy($file, $sink)) {
            return null;
        }

        return $size;
    }
}

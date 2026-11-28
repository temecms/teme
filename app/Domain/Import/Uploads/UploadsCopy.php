<?php

/**
 * A copy of an old site's uploads folder, as a zip or a folder on this
 * server: it finds a picture by its path under uploads/ and copies it into
 * a temporary file the caller releases. It never writes to, moves or deletes
 * the copy, and it reads a zip entry by entry without unpacking it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Uploads;

use App\Domain\Media\Downloads\DownloadedFile;
use App\Domain\Media\FileTypes;
use finfo;
use RuntimeException;
use ZipArchive;

final readonly class UploadsCopy
{
    public const string ZIP = 'zip';

    public const string FOLDER = 'folder';

    /**
     * The folders of protected plugins (order forms, logs, uploads kept for
     * their customers). Their files are never brought in from a copy: they
     * are private to the plugin, and the site refuses them to readers too.
     */
    public const array PROTECTED_FOLDERS = ['woocommerce_uploads', 'wc-logs', 'gravity_forms', 'wpforms', 'ninja-forms'];

    public function __construct(
        public string $kind,
        public string $path,
    ) {}

    /**
     * The picture at this address from the copy, copied into a temporary
     * file in the folder, or null when the copy lacks it, or holds one Teme
     * won't take (its type, or more bytes than the limit). The caller
     * releases the file; the copy itself is never touched.
     *
     * @throws RuntimeException when the temporary file can't be made
     */
    public function download(string $address, int $maxBytes, string $folder): ?DownloadedFile
    {
        $relative = self::relativePath($address);

        if ($relative === null || self::protectedReason($address) !== null) {
            return null;
        }

        $sink = tempnam($folder, 'teme-copy-');

        if ($sink === false) {
            throw new RuntimeException('Teme couldn’t make a temporary file for a picture from the uploads copy.');
        }

        $size = $this->copyTo($relative, $sink, $maxBytes);

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
     * The path under uploads/ that an address names, checked so that nothing
     * outside the copy can be named; null when the address has no uploads
     * part or its path isn't safe. The first uploads/ in the path is the one
     * that counts.
     */
    public static function relativePath(string $address): ?string
    {
        $path = parse_url($address, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $position = strpos(rawurldecode($path), '/uploads/');

        if ($position === false) {
            return null;
        }

        return self::safe(substr(rawurldecode($path), $position + strlen('/uploads/')));
    }

    /**
     * The relative path when every part of it is a plain name: no empty
     * part, no dot or dot-dot, no backslash, no NUL, no leading slash;
     * otherwise null.
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
     * Whether an address names a protected plugin's folder, whose files are
     * never brought in from a copy (PROTECTED_FOLDERS), and why; null otherwise.
     */
    public static function protectedReason(string $address): ?string
    {
        $relative = self::relativePath($address);

        if ($relative === null) {
            return null;
        }

        foreach (explode('/', $relative) as $part) {
            if (in_array($part, self::PROTECTED_FOLDERS, true)) {
                return 'It is in the folder of a protected plugin, '.$part.', so Teme doesn’t bring it from a copy.';
            }
        }

        return null;
    }

    /**
     * Whether a real path is the application's own folder, inside it, or a
     * folder above it: such a path is never attached as an uploads copy, since
     * it would expose the application's own files (.env, its code, its logs).
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
     * Copies the entry or file for a relative path into the sink, stopping
     * past the limit; the bytes copied, or null when the copy lacks it.
     */
    private function copyTo(string $relative, string $sink, int $maxBytes): ?int
    {
        return $this->kind === self::ZIP
            ? $this->copyZipEntry($relative, $sink, $maxBytes)
            : $this->copyFile($relative, $sink, $maxBytes);
    }

    private function copyZipEntry(string $relative, string $sink, int $maxBytes): ?int
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            // A zip made from wp-content, or from uploads itself, both hold the picture under uploads/.
            foreach ([$relative, 'uploads/'.$relative, 'wp-content/uploads/'.$relative] as $entry) {
                $index = $zip->locateName($entry);

                if ($index === false) {
                    continue;
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

                $copied = stream_copy_to_stream($stream, $out, $maxBytes + 1);
                fclose($stream);
                fclose($out);

                return $copied === false || $copied > $maxBytes ? null : (int) $copied;
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    private function copyFile(string $relative, string $sink, int $maxBytes): ?int
    {
        $base = realpath($this->path);
        $file = $base === false ? false : realpath($base.DIRECTORY_SEPARATOR.$relative);

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

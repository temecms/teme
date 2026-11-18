<?php

/**
 * Unpacks an uploaded ZIP into the extensions folder's packages/<vendor>/<name>/<version>/,
 * in the installer only, after checking it holds one safe package: no path
 * that leaves the folder, no symlink, no archive bomb, and a composer.json
 * and manifest that match the upload (I-211). Nothing in it is run.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

use App\Domain\Extensions\ExtensionKind;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final readonly class ArchiveReader
{
    /** The most entries an archive may hold. */
    public const int MOST_ENTRIES = 20_000;

    /** The most bytes an archive may unpack to. */
    public const int MOST_BYTES = 268_435_456;

    /** The most bytes the package's composer.json may hold, read into memory. */
    public const int MOST_COMPOSER_BYTES = 1_048_576;

    /** Composer's pattern for a package name. */
    private const string PACKAGE = '{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$}';

    /** A version that is safe as one folder's name. */
    private const string VERSION = '/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$/';

    /** The Unix mode bits of a symbolic link. */
    private const int SYMLINK = 0120000;

    /**
     * Checks the archive and unpacks it, stripped of its one top-level folder
     * when it has one. The same archive unpacked again changes nothing.
     *
     * @return string the package's folder
     *
     * @throws RuntimeException when the archive is refused, with the reason people read
     */
    public function unpack(string $archive, string $sha256, string $package, string $version, ExtensionKind $kind, string $packagesPath): string
    {
        if (! hash_equals(strtolower($sha256), hash('sha256', $archive))) {
            throw self::refused(__('The uploaded file has changed since it was uploaded. Upload it again.'));
        }

        if (preg_match(self::PACKAGE, $package) !== 1 || strlen($package) > 214 || preg_match(self::VERSION, $version) !== 1) {
            throw self::refused(__('The upload’s package name or version can’t be used. Upload it again.'));
        }

        $file = tempnam(sys_get_temp_dir(), 'teme-upload-');

        if ($file === false || file_put_contents($file, $archive) === false) {
            throw self::refused(__('The installer couldn’t write the upload to a temporary file.'));
        }

        $zip = new ZipArchive;
        $opened = false;

        try {
            $opened = $zip->open($file, ZipArchive::RDONLY | ZipArchive::CHECKCONS) === true;

            if (! $opened) {
                throw self::refused(__('The upload isn’t a ZIP archive the installer can read.'));
            }

            $entries = $this->entries($zip);
            $root = $this->root($entries);
            $this->checkPackage($zip, $root, $package, $version, $kind);

            return $this->extract($zip, $entries, $root, $package, $version, rtrim($packagesPath, '/'));
        } finally {
            if ($opened) {
                $zip->close();
            }

            @unlink($file);
        }
    }

    /**
     * Every entry's index and name, each checked, with their sizes summed
     * from the central directory before anything is written.
     *
     * @return array<int, string>
     */
    private function entries(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MOST_ENTRIES) {
            throw self::refused(__('The archive holds more than :count files.', ['count' => number_format(self::MOST_ENTRIES)]));
        }

        $entries = [];
        $seen = [];
        $bytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_ENC_RAW);
            $name = $zip->getNameIndex($index, ZipArchive::FL_ENC_RAW);

            if ($stat === false || $name === false) {
                throw self::refused(__('The upload isn’t a ZIP archive the installer can read.'));
            }

            if (! self::isSafeName($name)) {
                throw self::refused(__('The archive holds a file whose name could reach outside its folder: :name.', ['name' => self::shown($name)]));
            }

            if ($zip->getExternalAttributesIndex($index, $system, $attributes) && $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === self::SYMLINK) {
                throw self::refused(__('The archive holds a symbolic link: :name.', ['name' => self::shown($name)]));
            }

            if (isset($seen[$name])) {
                throw self::refused(__('The archive holds :name twice.', ['name' => self::shown($name)]));
            }

            $bytes += (int) $stat['size'];

            if ($bytes > self::MOST_BYTES) {
                throw self::refused(self::tooLarge());
            }

            $entries[$index] = $name;
            $seen[$name] = true;
        }

        return $entries;
    }

    /**
     * Whether an entry's name stays inside the folder it is unpacked into:
     * relative, with no empty, "." or ".." part, no backslash, no NUL and no
     * drive letter. A folder's name ends with a slash.
     */
    private static function isSafeName(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        foreach (explode('/', str_ends_with($name, '/') ? substr($name, 0, -1) : $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * The folder the package sits in inside the archive: none when
     * composer.json is at the root, else the one top-level folder every
     * entry is in.
     *
     * @param  array<int, string>  $entries
     */
    private function root(array $entries): string
    {
        if (in_array('composer.json', $entries, true)) {
            return '';
        }

        $tops = array_unique(array_map(fn (string $name): string => explode('/', $name, 2)[0], $entries));

        if (count($tops) !== 1) {
            throw self::refused(__('The archive must hold one package: composer.json at its root, or one folder with composer.json in it.'));
        }

        $top = reset($tops).'/';

        foreach ($entries as $name) {
            if ($name !== $top && ! str_starts_with($name, $top)) {
                throw self::refused(__('The archive must hold one package: composer.json at its root, or one folder with composer.json in it.'));
            }
        }

        return $top;
    }

    /**
     * Checks the package's composer.json, read as data, against the upload
     * and the request, and that its manifest is there.
     */
    private function checkPackage(ZipArchive $zip, string $root, string $package, string $version, ExtensionKind $kind): void
    {
        $stat = $zip->statName($root.'composer.json');

        if ($stat === false) {
            throw self::refused(__('The archive has no composer.json.'));
        }

        if ((int) $stat['size'] > self::MOST_COMPOSER_BYTES) {
            throw self::refused(__('The archive’s composer.json is larger than :size MB.', ['size' => intdiv(self::MOST_COMPOSER_BYTES, 1_048_576)]));
        }

        $json = $zip->getFromName($root.'composer.json', self::MOST_COMPOSER_BYTES);

        if ($json === false) {
            throw self::refused(__('The archive has no composer.json.'));
        }

        $composer = json_decode($json, true);

        if (! is_array($composer)) {
            throw self::refused(__('The archive’s composer.json isn’t valid JSON.'));
        }

        if (($composer['name'] ?? null) !== $package) {
            throw self::refused(__('The archive’s composer.json names :found, not :package.', ['found' => self::shown(is_string($composer['name'] ?? null) ? $composer['name'] : ''), 'package' => $package]));
        }

        if (($composer['version'] ?? null) !== $version) {
            throw self::refused(__('The archive’s composer.json gives version :found, not :version.', ['found' => self::shown(is_string($composer['version'] ?? null) ? $composer['version'] : ''), 'version' => $version]));
        }

        if (($composer['type'] ?? null) !== $kind->packageType()) {
            throw self::refused(__('The archive’s composer.json must give the type :type.', ['type' => $kind->packageType()]));
        }

        $manifest = $kind === ExtensionKind::Plugin ? 'teme-plugin.json' : 'teme-theme.json';

        if ($zip->locateName($root.$manifest) === false) {
            throw self::refused(__('The archive has no :manifest beside its composer.json.', ['manifest' => $manifest]));
        }
    }

    /**
     * Writes the entries into a hidden folder beside the target, counting
     * the bytes again as they are written, then moves it into place; when
     * the target is there already it must hold exactly the same files.
     *
     * @param  array<int, string>  $entries
     */
    private function extract(ZipArchive $zip, array $entries, string $root, string $package, string $version, string $packagesPath): string
    {
        $parent = "{$packagesPath}/{$package}";
        $target = "{$parent}/{$version}";

        self::folder($parent);

        $staging = "{$parent}/.{$version}.".bin2hex(random_bytes(6));
        self::folder($staging);

        try {
            $written = 0;

            foreach ($entries as $index => $name) {
                $relative = substr($name, strlen($root));

                if ($relative === '') {
                    continue;
                }

                if (str_ends_with($relative, '/')) {
                    self::folder($staging.'/'.rtrim($relative, '/'));

                    continue;
                }

                self::folder(dirname($staging.'/'.$relative));
                $written = $this->writeEntry($zip, $index, $staging.'/'.$relative, $written);
            }

            if (is_dir($target)) {
                if (self::listing($target) !== self::listing($staging)) {
                    throw self::refused(__('A different :package :version was uploaded before. Raise the version.', ['package' => $package, 'version' => $version]));
                }

                self::remove($staging);

                return $target;
            }

            if (! rename($staging, $target)) {
                throw self::refused(__('The installer couldn’t move the unpacked package into place.'));
            }

            return $target;
        } finally {
            if (is_dir($staging)) {
                self::remove($staging);
            }
        }
    }

    /**
     * Copies one entry to its file, 0644, and returns the bytes written so far.
     */
    private function writeEntry(ZipArchive $zip, int $index, string $path, int $written): int
    {
        $in = $zip->getStreamIndex($index);
        $out = @fopen($path, 'xb');

        if ($in === false || $out === false) {
            throw self::refused(__('The installer couldn’t unpack :name.', ['name' => self::shown(basename($path))]));
        }

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1_048_576);

                if ($chunk === false) {
                    throw self::refused(__('The installer couldn’t unpack :name.', ['name' => self::shown(basename($path))]));
                }

                $written += strlen($chunk);

                if ($written > self::MOST_BYTES) {
                    throw self::refused(self::tooLarge());
                }

                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        chmod($path, 0644);

        return $written;
    }

    /**
     * Makes a folder and those above it, each 0755 whatever the umask.
     */
    private static function folder(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        self::folder(dirname($path));

        if (! @mkdir($path) && ! is_dir($path)) {
            throw self::refused(__('The installer couldn’t make the folder for the package.'));
        }

        chmod($path, 0755);
    }

    /**
     * Every folder and file under a path, each file with its contents' hash,
     * in a stable order.
     *
     * @return array<string, string>
     */
    private static function listing(string $path): array
    {
        $listing = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($path) + 1);
            $listing[$relative] = $file->isDir() ? 'folder' : (string) hash_file('sha256', $file->getPathname());
        }

        ksort($listing);

        return $listing;
    }

    /**
     * Deletes a folder this reader made, and everything in it.
     */
    private static function remove(string $path): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }

    /**
     * An entry's name as it can be shown: printable, and not too long.
     */
    private static function shown(string $name): string
    {
        $printable = (string) preg_replace('/[^\x20-\x7E]/', '?', $name);

        return strlen($printable) > 120 ? substr($printable, 0, 117).'...' : $printable;
    }

    private static function tooLarge(): string
    {
        return __('The archive unpacks to more than :size MB.', ['size' => intdiv(self::MOST_BYTES, 1_048_576)]);
    }

    private static function refused(string $reason): RuntimeException
    {
        return new RuntimeException($reason);
    }
}

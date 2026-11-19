<?php

/**
 * Keeps an uploaded plugin or theme archive as data until the installer unpacks it (I-211): the web
 * process checks its size and that it is a ZIP, reads only its composer.json, never extracts a file,
 * and stores the bytes with the package's name, version and checksum. A person who holds
 * extensions.install, after their password, or the operator. Returns the upload's ID.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations;

use App\Domain\Extensions\Operations\Concerns\AuthorizesExtensionChanges;
use App\Domain\Extensions\Operations\Input\UploadExtensionInput;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;
use UnexpectedValueException;
use ZipArchive;

final class UploadExtension extends BaseOperation implements RequiresElevation
{
    use AuthorizesExtensionChanges;

    /** A ZIP archive's first bytes: a local file header. */
    private const string SIGNATURE = "PK\x03\x04";

    /** The most of composer.json the web process reads, in bytes; a larger one is refused unread. */
    private const int MOST_COMPOSER_BYTES = 65536;

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UploadExtensionInput $input */
        $this->package($input);
    }

    /**
     * @return array{id: string, package: string, version: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var UploadExtensionInput $input */
        ['package' => $package, 'version' => $version] = $this->package($input);
        $id = strtolower((string) Str::ulid());

        // bytea, written from base64 so no byte of the archive passes through as text.
        DB::insert(
            "INSERT INTO extension_uploads (id, file_name, size, sha256, package, version, bytes, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, decode(?, 'base64'), ?, ?)",
            [
                $id,
                mb_substr(basename(str_replace('\\', '/', $input->fileName)), 0, 255),
                strlen($input->bytes),
                hash('sha256', $input->bytes),
                $package,
                $version,
                base64_encode($input->bytes),
                $input->actorId() === null ? null : (int) $input->actorId(),
                CarbonImmutable::now(),
            ],
        );

        return ['id' => $id, 'package' => $package, 'version' => $version];
    }

    /**
     * The package's name and version, read from the archive's composer.json, at its top or inside its one
     * top-level folder; refused with the reason when the archive is too large, isn't a ZIP, or names no
     * package and version.
     *
     * @return array{package: string, version: string}
     */
    private function package(UploadExtensionInput $input): array
    {
        $limit = (int) config('teme.extensions.upload_max');

        if (strlen($input->bytes) > $limit) {
            throw self::refused(__('The archive is larger than :size MB.', ['size' => (string) round($limit / 1048576, 1)]));
        }

        if (! str_starts_with($input->bytes, self::SIGNATURE)) {
            throw self::refused(__('That file isn’t a ZIP archive.'));
        }

        $manifest = self::composerJson($input->bytes);
        $decoded = $manifest === null ? null : json_decode($manifest, true);

        if (! is_array($decoded)) {
            throw self::refused(__('The archive has no composer.json at its top, or one Teme can’t read.'));
        }

        $name = $decoded['name'] ?? null;
        $version = $decoded['version'] ?? null;

        if (! is_string($name) || strlen($name) > 214 || preg_match(RequestExtensionChange::PACKAGE_NAME, $name) !== 1 || ! is_string($version) || strlen($version) > 64 || ! self::isVersion($version)) {
            throw self::refused(__('The archive’s composer.json must give the package’s name and its version, such as 1.2.0.'));
        }

        return ['package' => $name, 'version' => $version];
    }

    /**
     * The archive's composer.json, read as data from its central directory, or null when it has none.
     */
    private static function composerJson(string $bytes): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'teme-upload-');

        if ($file === false) {
            return null;
        }

        try {
            file_put_contents($file, $bytes);
            $zip = new ZipArchive;

            if ($zip->open($file, ZipArchive::RDONLY) !== true) {
                return null;
            }

            $folders = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $folders[strstr((string) $zip->getNameIndex($index), '/', true) ?: ''] = true;
            }

            $inside = count($folders) === 1 && array_key_first($folders) !== '' ? array_key_first($folders).'/composer.json' : null;
            $name = $zip->statName('composer.json') !== false ? 'composer.json' : $inside;
            $stat = $name === null ? false : $zip->statName($name);

            // Capped, and refused unread when the archive says it is larger: a small archive can hold a huge entry.
            $found = $stat === false || $stat['size'] > self::MOST_COMPOSER_BYTES ? false : $zip->getFromName((string) $name, self::MOST_COMPOSER_BYTES);

            $zip->close();

            return $found === false ? null : $found;
        } finally {
            @unlink($file);
        }
    }

    private static function isVersion(string $version): bool
    {
        try {
            (new VersionParser)->normalize($version);

            return true;
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    private static function refused(string $reason): ValidationException
    {
        return ValidationException::withMessages(['archive' => [$reason]]);
    }
}

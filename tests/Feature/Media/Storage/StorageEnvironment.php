<?php

declare(strict_types=1);

namespace Tests\Feature\Media\Storage;

use Closure;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Deployment\Environment;

/**
 * Runs a test under the media variables it names, every other one unset,
 * with config/filesystems.php's media disks and config/media.php read again
 * from them, as a server starting with that environment would.
 */
final class StorageEnvironment
{
    /** Every variable the media disks read. */
    public const array NAMES = [
        'MEDIA_STORAGE', 'MEDIA_DISK', 'MEDIA_ORIGINALS_DISK', 'MEDIA_URL',
        'MEDIA_BUCKET', 'MEDIA_ORIGINALS_BUCKET', 'MEDIA_BUCKET_ROOT', 'MEDIA_ORIGINALS_BUCKET_ROOT',
        'MEDIA_BUCKET_ENDPOINT', 'MEDIA_BUCKET_REGION', 'MEDIA_BUCKET_PATH_STYLE',
        'MEDIA_BUCKET_ACCESS_KEY_ID', 'MEDIA_BUCKET_SECRET_ACCESS_KEY', 'MEDIA_BUCKET_URL', 'MEDIA_BUCKET_ACL',
    ];

    /** The disks config/filesystems.php gives media. */
    public const array DISKS = ['media', 'media-originals', 'media-bucket', 'media-originals-bucket'];

    /** A secret no output may show. */
    public const string SECRET = 'wJalrXUtnFEMI-the-test-secret-key';

    /**
     * Both roles in two buckets on one provider, with a CDN in front of the
     * public one: the setup the operator guide describes.
     *
     * @param  array<string, string|null>  $changes
     *
     * @return array<string, string|null>
     */
    public static function bucket(array $changes = []): array
    {
        return [
            'MEDIA_STORAGE' => 'bucket',
            'MEDIA_BUCKET' => 'teme-media',
            'MEDIA_ORIGINALS_BUCKET' => 'teme-originals',
            'MEDIA_BUCKET_ENDPOINT' => 'https://objects.example.test',
            'MEDIA_BUCKET_ACCESS_KEY_ID' => 'AKIATESTKEYID',
            'MEDIA_BUCKET_SECRET_ACCESS_KEY' => self::SECRET,
            'MEDIA_BUCKET_URL' => 'https://cdn.example.test',
            ...$changes,
        ];
    }

    /**
     * @template T
     *
     * @param  array<string, string|null>  $variables
     * @param  Closure(): T  $run
     *
     * @return T
     */
    public static function with(array $variables, Closure $run): mixed
    {
        return Environment::with([...array_fill_keys(self::NAMES, null), ...$variables], function () use ($run): mixed {
            self::read();

            return $run();
        });
    }

    /**
     * The media disks and config/media.php, read again from the environment.
     */
    public static function read(): void
    {
        /** @var array{disks: array<string, mixed>} $filesystems */
        $filesystems = require config_path('filesystems.php');

        foreach (self::DISKS as $disk) {
            config(["filesystems.disks.{$disk}" => $filesystems['disks'][$disk] ?? null]);
        }

        config(['media' => require config_path('media.php')]);
        Storage::forgetDisk(self::DISKS);
    }
}

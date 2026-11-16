<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'media' => [
            'driver' => 'local',
            'root' => storage_path('app/public/media'),
            // A pull CDN in front of the server's media sets MEDIA_URL (I-3456).
            'url' => rtrim((string) (env('MEDIA_URL') ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage/media'), '/'),
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],

        'media-originals' => [
            'driver' => 'local',
            'root' => storage_path('app/private/media-originals'),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        // Object storage for media (docs/architecture/media.md, "Storage"): two
        // buckets, since a provider makes a whole bucket public. Public access is
        // the bucket's, never the object's: MEDIA_BUCKET_ACL is only for a
        // provider that needs public-read on each object, and never on originals.
        'media-bucket' => [
            'driver' => 's3',
            'key' => env('MEDIA_BUCKET_ACCESS_KEY_ID'),
            'secret' => env('MEDIA_BUCKET_SECRET_ACCESS_KEY'),
            'region' => env('MEDIA_BUCKET_REGION') ?: 'us-east-1',
            'bucket' => env('MEDIA_BUCKET'),
            'root' => (string) env('MEDIA_BUCKET_ROOT', ''),
            'endpoint' => env('MEDIA_BUCKET_ENDPOINT') ?: null,
            'use_path_style_endpoint' => (bool) env('MEDIA_BUCKET_PATH_STYLE', false),
            'url' => env('MEDIA_BUCKET_URL') ?: null,
            // Keys carry the file version (StorageKeys), so a key's bytes never change.
            'options' => array_filter([
                'CacheControl' => 'public, max-age=31536000, immutable',
                'ACL' => env('MEDIA_BUCKET_ACL') ?: null,
            ]),
            'throw' => true,
            'report' => false,
        ],

        // No url: a disk without one has no public address. No ACL option:
        // Flysystem's default, private, is always sent.
        'media-originals-bucket' => [
            'driver' => 's3',
            'key' => env('MEDIA_BUCKET_ACCESS_KEY_ID'),
            'secret' => env('MEDIA_BUCKET_SECRET_ACCESS_KEY'),
            'region' => env('MEDIA_BUCKET_REGION') ?: 'us-east-1',
            'bucket' => env('MEDIA_ORIGINALS_BUCKET'),
            'root' => (string) env('MEDIA_ORIGINALS_BUCKET_ROOT', ''),
            'endpoint' => env('MEDIA_BUCKET_ENDPOINT') ?: null,
            'use_path_style_endpoint' => (bool) env('MEDIA_BUCKET_PATH_STYLE', false),
            'options' => ['CacheControl' => 'private, no-store'],
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

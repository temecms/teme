<?php

declare(strict_types=1);

use App\Domain\Deployment\SecretFiles;
use App\Domain\Media\MediaDisks;
use App\Domain\Site\Policy\PolicySources;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Media\Storage\FakeBucket;
use Tests\Feature\Media\Storage\StorageEnvironment;
use Tests\Fixtures\Deployment\Environment;
use Tests\Fixtures\Deployment\SecretFile;

const MDC_KEY = '01JTESTMEDIA0000000000000A/v1/holiday-w640.webp';

it('serves the server’s media under MEDIA_URL, and under APP_URL/storage/media without it, as before', function (): void {
    StorageEnvironment::with(['MEDIA_URL' => 'https://media.example.com'], function (): void {
        expect(MediaDisks::public()->url(MDC_KEY))->toBe('https://media.example.com/'.MDC_KEY);
    });

    StorageEnvironment::with([], function (): void {
        expect(config('media.disk'))->toBe('media')
            ->and(config('media.originals_disk'))->toBe('media-originals')
            ->and(MediaDisks::public()->url(MDC_KEY))->toBe('http://localhost/storage/media/'.MDC_KEY);
    });
});

it('switches both roles to the buckets with MEDIA_STORAGE=bucket, and keeps originals on the server when told', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        expect(config('media.disk'))->toBe('media-bucket')
            ->and(config('media.originals_disk'))->toBe('media-originals-bucket')
            ->and(MediaDisks::public()->url(MDC_KEY))->toBe('https://cdn.example.test/'.MDC_KEY);
    });

    StorageEnvironment::with(StorageEnvironment::bucket(['MEDIA_ORIGINALS_DISK' => 'media-originals']), function (): void {
        expect(config('media.disk'))->toBe('media-bucket')
            ->and(config('media.originals_disk'))->toBe('media-originals');
    });
});

it('fails on first use when MEDIA_STORAGE names no location, naming the value', function (): void {
    StorageEnvironment::with(['MEDIA_STORAGE' => 'cloud'], function (): void {
        expect(fn () => MediaDisks::public())->toThrow(InvalidArgumentException::class, 'unknown-media-storage-cloud');
    });
});

it('never gives an original’s disk a public address', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        expect(config('filesystems.disks.media-originals-bucket'))->not->toHaveKey('url')
            ->and(config('filesystems.disks.media-originals'))->not->toHaveKey('url')
            ->and(config('filesystems.disks.media-originals-bucket.options'))->not->toHaveKey('ACL');
    });
});

it('sends every public file as immutable, private by default, and public-read only when MEDIA_BUCKET_ACL says', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        $bucket = FakeBucket::on('media-bucket');
        Storage::disk('media-bucket')->put(MDC_KEY, 'webp bytes');

        $put = $bucket->last('PutObject');

        expect($put['params']['CacheControl'])->toBe('public, max-age=31536000, immutable')
            ->and($put['params']['ContentType'])->toBe('image/webp')
            ->and($put['params']['ACL'])->toBe('private')
            ->and($put['params']['Bucket'])->toBe('teme-media')
            ->and($bucket->objects[MDC_KEY])->toBe('webp bytes');
    });

    StorageEnvironment::with(StorageEnvironment::bucket(['MEDIA_BUCKET_ACL' => 'public-read']), function (): void {
        $public = FakeBucket::on('media-bucket');
        $originals = FakeBucket::on('media-originals-bucket');
        Storage::disk('media-bucket')->put(MDC_KEY, 'webp bytes');
        Storage::disk('media-originals-bucket')->put('01JTESTMEDIA0000000000000A/v1/holiday.jpg', 'jpeg bytes');

        expect($public->last('PutObject')['params']['ACL'])->toBe('public-read')
            ->and($originals->last('PutObject')['params']['ACL'])->toBe('private')
            ->and($originals->last('PutObject')['params']['CacheControl'])->toBe('private, no-store')
            ->and($originals->last('PutObject')['params']['Bucket'])->toBe('teme-originals');
    });
});

it('addresses MinIO by path and R2 by host', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(['MEDIA_BUCKET_ENDPOINT' => 'http://minio:9000', 'MEDIA_BUCKET_PATH_STYLE' => 'true']), function (): void {
        $bucket = FakeBucket::on('media-bucket');
        Storage::disk('media-bucket')->put(MDC_KEY, 'webp bytes');

        expect($bucket->last('PutObject')['uri'])->toBe('http://minio:9000/teme-media/'.MDC_KEY);
    });

    StorageEnvironment::with(StorageEnvironment::bucket(['MEDIA_BUCKET_ENDPOINT' => 'https://0123456789abcdef.r2.cloudflarestorage.com', 'MEDIA_BUCKET_REGION' => 'auto']), function (): void {
        $bucket = FakeBucket::on('media-bucket');
        Storage::disk('media-bucket')->put(MDC_KEY, 'webp bytes');

        expect($bucket->last('PutObject')['uri'])->toBe('https://teme-media.0123456789abcdef.r2.cloudflarestorage.com/'.MDC_KEY);
    });
});

it('allows the CDN’s origin in the content security policy when the public disk is the bucket', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        expect(PolicySources::media('http://localhost'))->toBe(['https://cdn.example.test']);
    });
});

it('reads the bucket’s secret key from a file', function (): void {
    $path = SecretFile::holding("the-bucket-secret\n");

    try {
        Environment::with(['MEDIA_BUCKET_SECRET_ACCESS_KEY' => null, 'MEDIA_BUCKET_SECRET_ACCESS_KEY_FILE' => $path], function (): void {
            SecretFiles::resolve();

            expect(Env::get('MEDIA_BUCKET_SECRET_ACCESS_KEY'))->toBe('the-bucket-secret');
        });
    } finally {
        SecretFile::remove($path);
    }
});

it('gives every Teme service on Dokploy every variable the media disks read, empty when unset', function (): void {
    $text = (string) file_get_contents(base_path('docker/compose.dokploy.yml'));
    $compose = Yaml::parse($text);

    foreach (['web', 'worker', 'worker-long', 'scheduler', 'deploy'] as $service) {
        expect(array_values(array_diff(StorageEnvironment::NAMES, array_keys($compose['services'][$service]['environment']))))->toBe([], $service);
    }

    foreach (StorageEnvironment::NAMES as $name) {
        expect($text)->toContain($name === 'MEDIA_STORAGE' ? "{$name}: \${{$name}:-server}" : "{$name}: \${{$name}:-}");
    }
});

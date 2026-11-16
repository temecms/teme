<?php

declare(strict_types=1);

use App\Domain\Media\Storage\BucketCheck;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Media\Storage\FakeBucket;
use Tests\Feature\Media\Storage\StorageEnvironment;

/**
 * Answers the GETs the check makes: the CDN serves what the public bucket
 * holds, and the originals bucket serves only a signed request.
 *
 * @param  array{public?: int, unsigned?: int}  $answers
 */
function bcFakeHttp(FakeBucket $public, FakeBucket $originals, array $answers = []): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($public, $originals, $answers) {
        $url = $request->url();
        $key = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        if (str_starts_with($url, 'https://cdn.example.test/')) {
            $status = $answers['public'] ?? 200;

            return Http::response($status === 200 ? ($public->objects[$key] ?? '') : 'Forbidden', $status, ['Cache-Control' => 'public, max-age=31536000, immutable']);
        }

        if (str_starts_with($url, 'https://teme-originals.objects.example.test/')) {
            return str_contains($url, 'X-Amz-Signature=')
                ? Http::response($originals->objects[$key] ?? '', 200)
                : Http::response('AccessDenied', $answers['unsigned'] ?? 403);
        }

        return Http::response('', 404);
    });
}

function bcRun(): array
{
    $code = Artisan::call('teme:media:bucket-check');

    return [$code, Artisan::output()];
}

it('passes every step, one line a step, and deletes its test files', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        [$public, $originals] = [FakeBucket::on('media-bucket'), FakeBucket::on('media-originals-bucket')];
        bcFakeHttp($public, $originals);

        [$code, $output] = bcRun();

        expect($code)->toBe(0)
            ->and($output)->toContain('Public bucket: writing a test file worked.')
            ->toContain('Public bucket: the public address serves the test file.')
            ->toContain('Originals bucket: refuses the test file without a signature.')
            ->toContain('Originals bucket: serves the test file through a signed address.')
            ->toContain('Public bucket: deleting the test file worked.')
            ->toContain('Originals bucket: deleting the test file worked.')
            ->and($public->names())->toBe(['PutObject', 'HeadObject', 'DeleteObject'])
            ->and($originals->names())->toBe(['PutObject', 'HeadObject', 'DeleteObject'])
            ->and($public->objects)->toBe([])
            ->and($originals->objects)->toBe([])
            ->and($public->last('PutObject')['params']['Key'])->toStartWith('_teme-check/');
    });
});

it('fails when the public address doesn’t serve the test file, and still deletes it', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        [$public, $originals] = [FakeBucket::on('media-bucket'), FakeBucket::on('media-originals-bucket')];
        bcFakeHttp($public, $originals, ['public' => 403]);

        [$code, $output] = bcRun();

        expect($code)->toBe(1)
            ->and($output)->toContain('the public address https://cdn.example.test/_teme-check/')->toContain('didn’t serve the test file (it answered 403)')
            ->and($public->objects)->toBe([])
            ->and($originals->objects)->toBe([]);
    });
});

it('fails when the originals bucket serves a file without a signature', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        [$public, $originals] = [FakeBucket::on('media-bucket'), FakeBucket::on('media-originals-bucket')];
        bcFakeHttp($public, $originals, ['unsigned' => 200]);

        [$code, $output] = bcRun();

        expect($code)->toBe(1)
            ->and($output)->toContain('Originals bucket: the originals bucket is public')
            ->and($originals->objects)->toBe([]);
    });
});

it('says in words that a bucket doesn’t accept ACLs', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(['MEDIA_BUCKET_ACL' => 'public-read']), function (): void {
        [$public, $originals] = [FakeBucket::on('media-bucket'), FakeBucket::on('media-originals-bucket')];
        $public->failNext('PutObject', 'AccessControlListNotSupported');
        bcFakeHttp($public, $originals);

        [$code, $output] = bcRun();

        expect($code)->toBe(1)
            ->and($output)->toContain('Public bucket: writing a test file failed: this bucket doesn’t accept ACLs: leave MEDIA_BUCKET_ACL empty (AccessControlListNotSupported).')
            ->and($public->names())->toBe(['PutObject']);
    });
});

it('refuses a setup that can’t be right before making any request', function (array $changes, string $sentence): void {
    StorageEnvironment::with(StorageEnvironment::bucket($changes), function () use ($sentence): void {
        [$public, $originals] = [FakeBucket::on('media-bucket'), FakeBucket::on('media-originals-bucket')];
        Http::preventStrayRequests();
        Http::fake();

        [$code, $output] = bcRun();

        expect($code)->toBe(1)
            ->and($output)->toContain($sentence)
            ->and($public->sent)->toBe([])
            ->and($originals->sent)->toBe([]);
        Http::assertNothingSent();
    });
})->with([
    'both on one bucket' => [['MEDIA_ORIGINALS_BUCKET' => 'teme-media'], 'are the same bucket'],
    'no public address' => [['MEDIA_BUCKET_URL' => null], 'MEDIA_BUCKET_URL isn’t set'],
    'an http endpoint on the internet' => [['MEDIA_BUCKET_ENDPOINT' => 'http://objects.example.test'], 'MEDIA_BUCKET_ENDPOINT is an http:// address on the internet'],
    'no bucket' => [['MEDIA_BUCKET' => null], 'MEDIA_BUCKET isn’t set'],
]);

it('allows http:// for a server on this network, as MinIO in development', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(['MEDIA_BUCKET_ENDPOINT' => 'http://minio:9000', 'MEDIA_BUCKET_PATH_STYLE' => 'true']), function (): void {
        expect(BucketCheck::refusals(['public', 'originals']))->toBe([]);
    });

    StorageEnvironment::with(StorageEnvironment::bucket(['MEDIA_BUCKET_ENDPOINT' => 'http://192.168.1.20:9000']), function (): void {
        expect(BucketCheck::refusals(['public', 'originals']))->toBe([]);
    });
});

it('never shows the secret key, whatever happens', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        [$public, $originals] = [FakeBucket::on('media-bucket'), FakeBucket::on('media-originals-bucket')];
        $public->failNext('PutObject', 'SignatureDoesNotMatch', 403);
        bcFakeHttp($public, $originals);

        [$code, $output] = bcRun();

        expect($code)->toBe(1)
            ->and($output)->toContain('the secret key is wrong (SignatureDoesNotMatch)')
            ->not->toContain(StorageEnvironment::SECRET)
            ->not->toContain('AKIATESTKEYID');
    });
});

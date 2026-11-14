<?php

declare(strict_types=1);

use App\Domain\Media\Downloads\FileDownloader;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\DownloadImage;
use App\Domain\Media\Operations\Input\DownloadImageInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Queue::fake();
    Sleep::fake();

    // A folder of this test's own, so what is left behind can be counted.
    $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-downloads-'.Str::random(10);
    mkdir($this->folder);
    app()->when(FileDownloader::class)->needs('$folder')->give($this->folder);

    $this->jpeg = dlJpeg();
    dlServe(['photos.example' => ['93.184.216.34'], 'inside.example' => ['10.0.0.5']], [
        // Closures: an answer served more than once needs a body of its own each time.
        'photos.example/harbour.jpg' => fn () => Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg']),
        'photos.example/report.pdf' => fn () => Http::response((string) file_get_contents(mediaPdf()), 200),
        'photos.example/big.jpg' => fn () => Http::response(dlJpeg(1200, 1000), 200),
    ]);
    $this->author = User::factory()->create()->assignRole('author');
    $this->download = fn (?User $actor, string $address = 'https://photos.example/harbour.jpg'): array => app(DownloadImage::class)
        ->execute(new DownloadImageInput($address, $actor === null ? null : (string) $actor->id));
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->folder.DIRECTORY_SEPARATOR.'*') ?: []);
    rmdir($this->folder);
});

it('brings an image into the media library with where it came from, as the person who asked (I-453)', function (): void {
    $result = ($this->download)($this->author);
    $media = Media::query()->findOrFail($result['mediaId']);

    expect($result['created'])->toBeTrue()
        ->and($media->kind)->toBe('image')
        ->and($media->original_filename)->toBe('harbour.jpg')
        ->and($media->source_url)->toBe('https://photos.example/harbour.jpg')
        ->and($media->uploaded_by)->toBe($this->author->id)
        ->and($media->alt)->toBeNull()
        ->and(glob($this->folder.DIRECTORY_SEPARATOR.'*'))->toBe([]);

    Queue::assertPushed(GenerateMediaVariants::class, fn (GenerateMediaVariants $job): bool => $job->mediaId === $media->id);
});

it('gives back the library\'s own item for a file it already has, adding nothing', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.jpg';
    file_put_contents($path, $this->jpeg);
    $existing = mediaUpload($this->author, $path);

    expect(($this->download)($this->author))->toBe(['mediaId' => $existing->id, 'created' => false])
        ->and(Media::query()->count())->toBe(1)
        ->and($existing->fresh()->source_url)->toBeNull()
        ->and(glob($this->folder.DIRECTORY_SEPARATOR.'*'))->toBe([]);
});

it('refuses under the address in the downloader\'s or the library\'s words, and keeps nothing', function (string $case, string $words): void {
    if ($case === 'too many pixels') {
        dlMediaLimits(['max_megapixels' => 1]);
    }

    $address = match ($case) {
        'a private host' => 'https://inside.example/a.jpg',
        'a document' => 'https://photos.example/report.pdf',
        'too many pixels' => 'https://photos.example/big.jpg',
        default => '   ',
    };

    expect(fn () => ($this->download)($this->author, $address))->toThrow(function (ValidationException $refusal) use ($words): void {
        expect($refusal->errors())->toBe(['address' => [$words]]);
    });

    expect(Media::query()->count())->toBe(0)
        ->and(glob($this->folder.DIRECTORY_SEPARATOR.'*'))->toBe([]);
})->with([
    'a private host' => ['a private host', 'inside.example is on a private network, so Teme won’t connect to it.'],
    'a document' => ['a document', 'This file isn’t an image.'],
    'too many pixels' => ['too many pixels', 'The image is larger than the 1 megapixel limit.'],
    'no address' => ['no address', 'Give the image’s address.'],
]);

it('writes nothing, and asks no site, for anyone who may not upload', function (string $case): void {
    $actor = $case === 'a contributor' ? User::factory()->create()->assignRole('contributor') : null;

    expect(fn () => ($this->download)($actor))->toThrow(AuthorizationException::class, 'Not permitted to upload files.')
        ->and(Media::query()->count())->toBe(0);

    Http::assertNothingSent();
})->with([
    'a contributor' => ['a contributor'],
    'nobody, away from the operator\'s shell' => ['nobody'],
]);

it('lets the operator bring an image in from the shell', function (): void {
    $result = scElevated(fn (): array => ($this->download)(null));

    expect($result['created'])->toBeTrue()
        ->and(Media::query()->findOrFail($result['mediaId'])->uploaded_by)->toBeNull();
});

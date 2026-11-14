<?php

declare(strict_types=1);

use App\Domain\Ai\Tasks\AltText\AltTextImage;
use App\Domain\Media\Images\ImageEngine;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
});

it('sends the 960 copy, or a narrower original’s own, as a WebP still, never the original (I-319)', function (int $width, int $height, string $variant, array $size): void {
    $media = altImage($this->author, $width, $height);
    $image = app(AltTextImage::class)->copyOf($media);
    $read = getimagesizefromstring($image->bytes);

    expect($image->mediaType)->toBe('image/webp')
        ->and($read === false ? null : [$read[0], $read[1], $read['mime']])->toBe([...$size, 'image/webp'])
        ->and(AltTextImage::variantOf($media))->toBe(['path' => $media->variants[$variant]['path'], 'width' => $size[0], 'height' => $size[1]])
        ->and($image->bytes)->not->toBe(Storage::disk('media-originals')->get($media->original_path));
})->with([
    'wider than 960' => [1200, 800, 'w960', [960, 640]],
    'narrower than 960' => [700, 500, 'w700', [700, 500]],
]);

it('cuts a tall copy to 1,568 pixels on its longer side, keeping its shape', function (): void {
    $media = altImage($this->author, 960, 4000);
    $read = getimagesizefromstring(app(AltTextImage::class)->copyOf($media)->bytes);

    expect($media->variants['w960']['height'])->toBe(4000)
        ->and($read[0])->toBe(376)
        ->and($read[1])->toBeGreaterThanOrEqual(1560)->toBeLessThanOrEqual(1568);
});

it('tries lower qualities, then a shorter side, until the copy fits under its cap', function (): void {
    $media = altImage($this->author);

    // An engine whose copies are too large until its fifth.
    $engine = new class implements ImageEngine
    {
        /** @var list<array{0: int, 1: int}> */
        public array $asked = [];

        public function canDecode(string $mimeType): bool
        {
            return true;
        }

        public function inspect(string $path): array
        {
            return ['width' => 1, 'height' => 1, 'animated' => false];
        }

        public function variants(string $source, array $widths, int $thumbnail, int $focalX, int $focalY, string $directory, array $share = []): array
        {
            return [];
        }

        public function reencode(string $bytes, int $longestSide, int $quality): ?array
        {
            $this->asked[] = [$longestSide, $quality];

            return ['bytes' => str_repeat('x', count($this->asked) < 5 ? AltTextImage::MAX_BYTES + 1 : 10), 'width' => $longestSide, 'height' => $longestSide];
        }
    };
    app()->instance(ImageEngine::class, $engine);

    $image = app(AltTextImage::class)->copyOf($media);

    expect($engine->asked)->toBe([[1568, 80], [1568, 60], [1568, 40], [784, 80], [784, 60]])
        ->and(strlen($image->bytes))->toBe(10);
});

it('sends nothing for an image with no copy, or a copy that isn’t one, and says it is gone', function (string $case): void {
    $media = $case === 'a document' ? mediaUpload($this->author, mediaPdf(), 'Fares.pdf') : altImage($this->author);

    if ($case === 'processing that failed') {
        DB::table('media')->where('id', $media->id)->update(['variants' => '{}', 'processing' => 'failed']);
    }

    if ($case === 'a copy that isn’t an image') {
        Storage::disk('media')->put($media->variants['w960']['path'], 'not an image');
    }

    if ($case === 'a copy that has gone') {
        Storage::disk('media')->delete($media->variants['w960']['path']);
    }

    expect(fn () => app(AltTextImage::class)->copyOf($media->refresh()))->toThrow(ModelNotFoundException::class);
})->with(['a document', 'processing that failed', 'a copy that isn’t an image', 'a copy that has gone']);

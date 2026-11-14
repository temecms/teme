<?php

declare(strict_types=1);

use App\Domain\Media\Operations\Input\ReplaceMediaFileInput;
use App\Domain\Media\Operations\ReplaceMediaFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Media\MediaStored;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->nobody = User::factory()->create();
});

it('sends MediaStored for an upload and for a replaced file', function (): void {
    $heard = hkHear(MediaStored::class);

    $media = mediaUpload($this->author, hkPng(64, 48), 'Harbour.png');
    app(ReplaceMediaFile::class)->execute(new ReplaceMediaFileInput($media->id, mediaJpeg(320, 200), 'Harbour at dusk.jpg', (string) $this->author->id));

    expect($heard)->toHaveCount(2)
        ->and([$heard[0]->replaced, $heard[1]->replaced])->toBe([false, true])
        ->and($heard[0]->media->id)->toBe($media->id)
        ->and($heard[0]->media->id)->toBe(strtoupper($heard[0]->media->id))
        ->and($heard[0]->media->id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($heard[0]->media->kind)->toBe('image')
        ->and($heard[0]->media->mimeType)->toBe('image/png')
        ->and($heard[0]->media->filename)->toBe('Harbour.png')
        ->and($heard[0]->media->title)->toBe('Harbour')
        ->and($heard[0]->media->sizeBytes)->toBeGreaterThan(0)
        ->and([$heard[0]->media->width, $heard[0]->media->height])->toBe([64, 48])
        ->and($heard[0]->actorId)->toBe((string) $this->author->id)
        ->and($heard[1]->media->mimeType)->toBe('image/jpeg')
        ->and($heard[1]->media->filename)->toBe('Harbour at dusk.jpg')
        ->and([$heard[1]->media->width, $heard[1]->media->height])->toBe([320, 200]);
});

it('announces nothing when an upload or a replacement is refused', function (): void {
    $other = mediaUpload($this->author, hkPng(64, 48), 'Harbour.png');
    $heard = hkHear(MediaStored::class);

    expect(fn () => mediaUpload($this->nobody, hkPng(32, 32), 'Nope.png'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReplaceMediaFile::class)->execute(new ReplaceMediaFileInput($other->id, mediaJpeg(320, 200), 'Nope.jpg', (string) $this->nobody->id)))->toThrow(AuthorizationException::class)
        ->and($heard->getArrayCopy())->toBe([]);
});

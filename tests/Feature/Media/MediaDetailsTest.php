<?php

declare(strict_types=1);

use App\Domain\Media\Jobs\DeleteMediaFiles;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\ReplaceMediaFileInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\ReplaceMediaFile;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
});

function mediaDetails(Media $media, User $actor, array $changes = []): Media
{
    return app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput(...array_merge([
        'mediaId' => $media->id,
        'actorId' => (string) $actor->id,
        'title' => $media->title,
        'alt' => $media->alt,
        'caption' => $media->caption,
        'credit' => $media->credit,
        'focalX' => $media->focal_x,
        'focalY' => $media->focal_y,
    ], $changes)));
}

it('saves alt text, caption and credit, trimmed, with empty meaning none', function (): void {
    $media = mediaUpload($this->author, mediaJpeg());

    $saved = mediaDetails($media, $this->author, ['title' => ' Harbour ', 'alt' => ' Boats at dawn ', 'caption' => '', 'credit' => 'A. Photographer']);

    expect([$saved->title, $saved->alt, $saved->caption, $saved->credit])->toBe(['Harbour', 'Boats at dawn', null, 'A. Photographer']);
});

it('remakes the variants when the focal point moves, and only then', function (): void {
    Queue::fake();
    $media = mediaUpload($this->author, mediaJpeg());

    mediaDetails($media->refresh(), $this->author, ['alt' => 'Unchanged focus']);
    Queue::assertPushed(GenerateMediaVariants::class, 1);

    mediaDetails($media->refresh(), $this->author, ['focalX' => 20]);
    Queue::assertPushed(GenerateMediaVariants::class, 2);
    expect($media->refresh()->processing)->toBe('pending');
});

it('replaces the file under the same ID, and deletes the old version afterwards', function (): void {
    $media = mediaUpload($this->author, mediaJpeg(900, 600))->refresh();
    $oldOriginal = $media->original_path;
    $oldVariant = $media->variants['thumb']['path'];

    Queue::fake();
    $replaced = app(ReplaceMediaFile::class)->execute(new ReplaceMediaFileInput($media->id, mediaJpeg(1600, 900), 'New harbour.jpg', (string) $this->author->id));

    expect($replaced->id)->toBe($media->id)
        ->and($replaced->file_version)->toBe(2)
        ->and($replaced->original_path)->toBe("{$media->id}/v2/new-harbour.jpg")
        ->and([$replaced->width, $replaced->height])->toBe([1600, 900])
        ->and($replaced->processing)->toBe('pending')
        ->and($replaced->variants['thumb']['path'])->toBe($oldVariant);

    Storage::disk('media-originals')->assertExists($replaced->original_path);
    Queue::assertPushed(DeleteMediaFiles::class, fn (DeleteMediaFiles $job): bool => $job->pathsByDisk === ['originals' => [$oldOriginal]]);
    Queue::assertPushed(GenerateMediaVariants::class, fn (GenerateMediaVariants $job): bool => $job->fileVersion === 2);
});

it('refuses to replace a file with one of another kind', function (): void {
    $media = mediaUpload($this->author, mediaJpeg());

    expect(fn () => app(ReplaceMediaFile::class)->execute(new ReplaceMediaFileInput($media->id, mediaPdf(), 'report.pdf', (string) $this->author->id)))
        ->toThrow(ValidationException::class);
});

it('writes nothing when someone may not change the item', function (): void {
    $media = mediaUpload($this->author, mediaJpeg());
    $other = User::factory()->create()->assignRole('author');

    expect(fn () => mediaDetails($media, $other, ['alt' => 'Nope']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReplaceMediaFile::class)->execute(new ReplaceMediaFileInput($media->id, mediaJpeg(), 'x.jpg', (string) $other->id)))->toThrow(AuthorizationException::class)
        ->and($media->refresh()->alt)->toBeNull()
        ->and($media->file_version)->toBe(1);
});

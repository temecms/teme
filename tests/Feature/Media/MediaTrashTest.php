<?php

declare(strict_types=1);

use App\Domain\Media\Jobs\DeleteMediaFiles;
use App\Domain\Media\MediaCounts;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\DeleteMediaPermanently;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\RestoreMedia;
use App\Domain\Media\Operations\TrashMedia;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

function mediaAct(string $operation, Media $media, ?User $actor): mixed
{
    return app($operation)->execute(new MediaActionInput($media->id, $actor === null ? null : (string) $actor->id));
}

it('trashes and restores an item, keeping its files, and counts it', function (): void {
    $media = mediaUpload($this->author, mediaJpeg())->refresh();

    mediaAct(TrashMedia::class, $media, $this->author);
    expect(MediaCounts::all())->toBe(['image' => ['trash' => 1]]);
    Storage::disk('media-originals')->assertExists($media->original_path);

    mediaAct(RestoreMedia::class, $media, $this->author);
    expect(MediaCounts::all())->toBe(['image' => ['live' => 1]])
        ->and(Media::query()->find($media->id))->not->toBeNull();
});

it('deletes a trashed item for good, and its files after commit', function (): void {
    $media = mediaUpload($this->author, mediaJpeg())->refresh();
    mediaAct(TrashMedia::class, $media, $this->author);

    Queue::fake();
    mediaAct(DeleteMediaPermanently::class, $media, $this->author);

    expect(Media::withTrashed()->find($media->id))->toBeNull()
        ->and(MediaCounts::all())->toBe([]);

    Queue::assertPushed(DeleteMediaFiles::class, fn (DeleteMediaFiles $job): bool => $job->pathsByDisk['originals'] === [$media->original_path]
        && $job->pathsByDisk['public'] === array_column($media->variants, 'path'));
});

it('refuses to delete an item that is not in the trash', function (): void {
    $media = mediaUpload($this->author, mediaJpeg());

    expect(fn () => mediaAct(DeleteMediaPermanently::class, $media, $this->author))->toThrow(ValidationException::class);
});

it('writes nothing when someone may not remove the item', function (): void {
    $media = mediaUpload($this->author, mediaJpeg());
    $other = User::factory()->create()->assignRole('author');

    expect(fn () => mediaAct(TrashMedia::class, $media, $other))->toThrow(AuthorizationException::class)
        ->and(fn () => mediaAct(DeleteMediaPermanently::class, $media, null))->toThrow(AuthorizationException::class)
        ->and($media->refresh()->trashed())->toBeFalse();
});

it('purges media trashed longer ago than the retention period', function (): void {
    $old = mediaUpload($this->author, mediaJpeg(400, 300));
    $recent = mediaUpload($this->author, mediaPdf(), 'recent.pdf');
    mediaAct(TrashMedia::class, $old, $this->author);
    mediaAct(TrashMedia::class, $recent, $this->author);
    DB::table('media')->where('id', $old->id)->update(['deleted_at' => now()->subDays(31)]);

    $this->artisan('teme:trash:purge')->assertSuccessful();

    expect(Media::withTrashed()->find($old->id))->toBeNull()
        ->and(Media::withTrashed()->find($recent->id))->not->toBeNull();
});

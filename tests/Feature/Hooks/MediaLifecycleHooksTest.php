<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Media\Operations\DeleteMediaPermanently;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\RestoreMedia;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Media\MediaDeleted;
use Teme\Contracts\Media\MediaRestored;
use Teme\Contracts\Media\MediaTrashed;
use Teme\Contracts\Media\MediaUpdated;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->nobody = User::factory()->create();
});

/**
 * Records each hook's payload and the open transaction depth at the moment a
 * listener runs, so a test can prove the hook came after the commit.
 *
 * @return ArrayObject<int, array{hook: string, payload: object, depth: int}>
 */
function mediaHookRecord(string ...$hooks): ArrayObject
{
    $heard = new ArrayObject;
    $core = app(HookDispatcher::class)->subscriber('core');

    foreach ($hooks as $hook) {
        $core->on($hook, function (object $payload) use ($heard, $hook): void {
            $heard[] = ['hook' => $hook, 'payload' => $payload, 'depth' => DB::transactionLevel()];
        });
    }

    return $heard;
}

it('sends MediaUpdated once, after commit, when a file’s details change', function (): void {
    $media = mediaUpload($this->author, hkPng(64, 48), 'Harbour.png');
    $heard = mediaHookRecord(MediaUpdated::class);
    $base = DB::transactionLevel();

    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($media->id, (string) $this->author->id, 'Harbour at dusk', 'A boat', null, null));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['depth'])->toBe($base)
        ->and($heard[0]['payload']->media->id)->toBe($media->id)
        ->and($heard[0]['payload']->media->title)->toBe('Harbour at dusk')
        ->and($heard[0]['payload']->media->filename)->toBe('Harbour.png')
        ->and($heard[0]['payload']->actorId)->toBe((string) $this->author->id);
});

it('sends MediaTrashed and MediaRestored once each, after commit', function (): void {
    $media = mediaUpload($this->author, hkPng(64, 48), 'Harbour.png');
    $heard = mediaHookRecord(MediaTrashed::class, MediaRestored::class);
    $base = DB::transactionLevel();

    app(TrashMedia::class)->execute(new MediaActionInput($media->id, (string) $this->author->id));
    app(RestoreMedia::class)->execute(new MediaActionInput($media->id, (string) $this->author->id));

    expect(array_column($heard->getArrayCopy(), 'hook'))->toBe([MediaTrashed::class, MediaRestored::class])
        ->and(array_column($heard->getArrayCopy(), 'depth'))->toBe([$base, $base])
        ->and($heard[0]['payload']->media->id)->toBe($media->id)
        ->and($heard[0]['payload']->actorId)->toBe((string) $this->author->id)
        ->and($heard[1]['payload']->media->id)->toBe($media->id);
});

it('sends MediaDeleted with the file as it was before the row went, after commit', function (): void {
    $media = mediaUpload($this->author, hkPng(64, 48), 'Harbour.png');
    app(TrashMedia::class)->execute(new MediaActionInput($media->id, (string) $this->author->id));
    $heard = mediaHookRecord(MediaDeleted::class);
    $base = DB::transactionLevel();

    app(DeleteMediaPermanently::class)->execute(new MediaActionInput($media->id, (string) $this->author->id));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['depth'])->toBe($base)
        ->and($heard[0]['payload']->media->id)->toBe($media->id)
        ->and($heard[0]['payload']->media->filename)->toBe('Harbour.png')
        ->and([$heard[0]['payload']->media->width, $heard[0]['payload']->media->height])->toBe([64, 48])
        ->and($heard[0]['payload']->actorId)->toBe((string) $this->author->id);
});

it('sends none of the media lifecycle hooks when the change is refused', function (): void {
    $media = mediaUpload($this->author, hkPng(64, 48), 'Harbour.png');
    $heard = mediaHookRecord(MediaUpdated::class, MediaTrashed::class, MediaRestored::class, MediaDeleted::class);

    expect(fn () => app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($media->id, (string) $this->nobody->id, 'Nope', null, null, null)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(TrashMedia::class)->execute(new MediaActionInput($media->id, (string) $this->nobody->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteMediaPermanently::class)->execute(new MediaActionInput($media->id, (string) $this->nobody->id)))->toThrow(AuthorizationException::class)
        ->and($heard->getArrayCopy())->toBe([]);
});

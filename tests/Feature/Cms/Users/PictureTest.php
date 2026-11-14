<?php

declare(strict_types=1);

use App\Domain\Media\Operations\DeleteMediaPermanently;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\Operations\Input\RemovePictureInput;
use App\Domain\Users\Operations\Input\SetPictureInput;
use App\Domain\Users\Operations\RemovePicture;
use App\Domain\Users\Operations\SetPicture;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
});

function pictureSet(User $user, string $mediaId, ?User $actor): void
{
    app(SetPicture::class)->execute(new SetPictureInput((string) $user->id, $mediaId, $actor === null ? null : (string) $actor->id));
}

/**
 * @return array{name: string, removed: bool}
 */
function pictureRemove(User $user, ?User $actor): array
{
    return app(RemovePicture::class)->execute(new RemovePictureInput((string) $user->id, $actor === null ? null : (string) $actor->id));
}

it('sets your own picture to an image in Media', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $image = pageImage($author, 400, 400);

    app(SetPicture::class)->execute(new SetPictureInput((string) $author->id, $image->id, (string) $author->id));

    expect($author->refresh()->picture_media_id)->toBe($image->id);
});

it('writes nothing when an administrator chooses another person’s picture', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $author = User::factory()->create()->assignRole('author');
    $image = pageImage($admin, 400, 400);

    expect(fn () => app(SetPicture::class)->execute(new SetPictureInput((string) $author->id, $image->id, (string) $admin->id)))
        ->toThrow(AuthorizationException::class);

    expect($author->refresh()->picture_media_id)->toBeNull();
});

it('writes nothing when no one is signed in, even at the shell', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $image = pageImage($author, 400, 400);

    app()->instance(Elevation::class, new OperatorElevation);

    expect(fn () => app(SetPicture::class)->execute(new SetPictureInput((string) $author->id, $image->id, null)))
        ->toThrow(AuthorizationException::class);

    expect($author->refresh()->picture_media_id)->toBeNull();
});

it('refuses anything but a live image in Media, writing nothing', function (string $what): void {
    $author = User::factory()->create()->assignRole('author');
    $mediaId = match ($what) {
        'a document' => mediaUpload($author, mediaPdf(), 'Terms.pdf')->id,
        'a trashed image' => (function () use ($author): string {
            $image = pageImage($author, 400, 400);
            app(TrashMedia::class)->execute(new MediaActionInput($image->id, (string) $author->id));

            return $image->id;
        })(),
        'an unknown ID' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
    };

    try {
        pictureSet($author, $mediaId, $author);
        $this->fail('The picture was accepted.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe(['media' => ['Choose an image from Media.']]);
    }

    expect($author->refresh()->picture_media_id)->toBeNull();
})->with(['a document', 'a trashed image', 'an unknown ID']);

it('removes your own picture', function (): void {
    $author = User::factory()->create(['name' => 'Bea Author'])->assignRole('author');
    pictureSet($author, pageImage($author, 400, 400)->id, $author);

    expect(pictureRemove($author, $author))->toBe(['name' => 'Bea Author', 'removed' => true])
        ->and($author->refresh()->picture_media_id)->toBeNull();
});

it('lets an administrator remove another person’s picture', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $author = User::factory()->create()->assignRole('author');
    pictureSet($author, pageImage($author, 400, 400)->id, $author);

    pictureRemove($author, $admin);

    expect($author->refresh()->picture_media_id)->toBeNull();
});

it('writes nothing when an author removes another person’s picture', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $other = User::factory()->create()->assignRole('author');
    $image = pageImage($other, 400, 400);
    pictureSet($other, $image->id, $other);

    expect(fn () => pictureRemove($other, $author))->toThrow(AuthorizationException::class);

    expect($other->refresh()->picture_media_id)->toBe($image->id);
});

it('answers that nothing was removed when there is no picture', function (): void {
    $author = User::factory()->create(['name' => 'Bea Author'])->assignRole('author');

    expect(pictureRemove($author, $author))->toBe(['name' => 'Bea Author', 'removed' => false]);
});

it('clears the picture when its image is deleted for good', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $author = User::factory()->create()->assignRole('author');
    $image = pageImage($author, 400, 400);
    pictureSet($author, $image->id, $author);

    app(TrashMedia::class)->execute(new MediaActionInput($image->id, (string) $admin->id));
    expect($author->refresh()->picture_media_id)->toBe($image->id);

    app(DeleteMediaPermanently::class)->execute(new MediaActionInput($image->id, (string) $admin->id));

    expect($author->refresh()->picture_media_id)->toBeNull();
});

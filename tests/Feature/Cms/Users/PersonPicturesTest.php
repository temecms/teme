<?php

declare(strict_types=1);

use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\RestoreMedia;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Users\Operations\Input\SetPictureInput;
use App\Domain\Users\Operations\SetPicture;
use App\Domain\Users\Queries\PersonPictures;
use App\Models\User;
use Database\Seeders\RoleSeeder;
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

function picturesGive(User $user): string
{
    $image = pageImage($user, 400, 400);
    app(SetPicture::class)->execute(new SetPictureInput((string) $user->id, $image->id, (string) $user->id));

    return $image->id;
}

it('gives the image’s square thumbnail address, made from the site’s address', function (): void {
    $imageId = picturesGive($this->author);
    $thumb = (string) DB::table('media')->where('id', $imageId)->value('variants');
    $path = json_decode($thumb, true)['thumb']['path'];

    expect(app(PersonPictures::class)->of($this->author->id))->toEndWith($path);

    config(['app.url' => 'https://new.example.test', 'filesystems.disks.media.url' => 'https://new.example.test/storage/media']);
    app('filesystem')->forgetDisk('media');

    expect(app(PersonPictures::class)->of($this->author->id))->toBe('https://new.example.test/storage/media/'.$path);
});

it('gives none while the image is in the trash, and gives it again once restored', function (): void {
    $imageId = picturesGive($this->author);

    app(TrashMedia::class)->execute(new MediaActionInput($imageId, (string) $this->author->id));
    expect(app(PersonPictures::class)->of($this->author->id))->toBeNull();

    app(RestoreMedia::class)->execute(new MediaActionInput($imageId, (string) $this->author->id));
    expect(app(PersonPictures::class)->of($this->author->id))->not->toBeNull();
});

it('gives none for an account without a picture', function (): void {
    expect(app(PersonPictures::class)->of($this->author->id))->toBeNull()
        ->and(app(PersonPictures::class)->for([$this->author->id]))->toBe([]);
});

it('reads every account’s picture in one query', function (): void {
    $people = [$this->author, User::factory()->create()->assignRole('author'), User::factory()->create()->assignRole('author')];

    foreach ($people as $person) {
        picturesGive($person);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $pictures = app(PersonPictures::class)->for(array_map(fn (User $person): int => (int) $person->id, $people));
    DB::disableQueryLog();

    expect($pictures)->toHaveCount(3)
        ->and(DB::getQueryLog())->toHaveCount(1);
});

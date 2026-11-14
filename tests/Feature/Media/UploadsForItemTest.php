<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Media\Exceptions\DuplicateMediaException;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\DownloadImage;
use App\Domain\Media\Operations\Input\DownloadImageInput;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\UploadMedia;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Queue::fake();
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->draft = lifecycleDraft($this->author, 'Harbour news');
});

/**
 * A file uploaded through the real Operation for $item, by $actor, or by the
 * operator when $actor is null (inside scElevated()). Without $path, each
 * call makes an image of a new width, so none is another's duplicate.
 */
function ufUpload(?User $actor, ?string $item, ?string $path = null, bool $allowDuplicate = false): Media
{
    static $width = 320;

    return app(UploadMedia::class)->execute(new UploadMediaInput(
        $path ?? mediaJpeg($width++, 240),
        'harbour.jpg',
        $actor === null ? null : (string) $actor->id,
        $allowDuplicate,
        uploadedFor: $item,
    ));
}

it('records the item a person uploads a file for, when they may edit it (I-553)', function (): void {
    $media = ufUpload($this->author, $this->draft->id);

    expect($media->uploaded_for)->toBe($this->draft->id)
        ->and(DB::table('media')->where('id', $media->id)->value('uploaded_for'))->toBe($this->draft->id)
        // An editor may edit anyone's item, so an editor's upload records it too.
        ->and(ufUpload($this->editor, $this->draft->id)->uploaded_for)->toBe($this->draft->id);
});

it('uploads the file but records nothing for an item its uploader may not edit', function (): void {
    $theirs = lifecycleDraft($this->editor, 'Ferry times');

    $media = ufUpload($this->author, $theirs->id);

    expect($media->uploaded_for)->toBeNull()
        ->and(Media::query()->count())->toBe(1);
});

it('records nothing for an item in the trash, one that is gone, or what isn’t an item’s ID', function (string $case): void {
    if ($case === 'in the trash') {
        app(TrashContent::class)->execute(new ContentActionInput($this->draft->id, (string) $this->author->id));
    }

    $item = match ($case) {
        'in the trash' => $this->draft->id,
        'gone' => strtolower((string) Str::ulid()),
        'not an ID' => 'harbour-news',
        'upper case' => strtoupper($this->draft->id),
    };

    expect(ufUpload($this->author, $item)->uploaded_for)->toBeNull()
        ->and(Media::query()->count())->toBe(1);
})->with(['in the trash', 'gone', 'not an ID', 'upper case']);

it('lets the operator record any item from the server’s shell', function (): void {
    $media = scElevated(fn (): Media => ufUpload(null, $this->draft->id));

    expect($media->uploaded_for)->toBe($this->draft->id)
        ->and($media->uploaded_by)->toBeNull();
});

it('reads the item under a share lock, so a deletion of the item waits for the upload to commit', function (): void {
    $statements = planStatements(fn () => ufUpload($this->author, $this->draft->id));
    $read = collect($statements)->first(fn (array $entry): bool => str_contains($entry['query'], 'from "contents"'));

    expect($read)->not->toBeNull()
        ->and($read['query'])->toEndWith('for share');
});

it('writes nothing, item or not, when the uploader may not upload', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $own = lifecycleDraft($contributor, 'Notes');

    expect(fn () => ufUpload($contributor, $own->id))->toThrow(AuthorizationException::class)
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk('media-originals')->allFiles())->toBe([]);
});

it('marks nothing on the file the library already has when an upload is its duplicate, and records the item on a copy uploaded anyway', function (): void {
    $path = mediaJpeg(800, 600);
    $existing = ufUpload($this->author, null, $path);

    expect(fn () => ufUpload($this->author, $this->draft->id, $path))->toThrow(DuplicateMediaException::class)
        ->and($existing->fresh()?->uploaded_for)->toBeNull();

    $copy = ufUpload($this->author, $this->draft->id, $path, allowDuplicate: true);

    expect($copy->uploaded_for)->toBe($this->draft->id)
        ->and($existing->fresh()?->uploaded_for)->toBeNull();
});

it('records the item the media modal’s upload names, through the upload request', function (): void {
    $theirs = lifecycleDraft($this->editor, 'Ferry times');
    $file = fn (int $width, string $name): UploadedFile => new UploadedFile(mediaJpeg($width, 480), $name, null, null, true);

    $this->actingAs($this->author)
        ->postJson(route('media.store'), ['file' => $file(640, 'harbour.jpg'), 'uploaded_for' => $this->draft->id])
        ->assertCreated();
    $this->actingAs($this->author)
        ->postJson(route('media.store'), ['file' => $file(700, 'ferry.jpg'), 'uploaded_for' => $theirs->id])
        ->assertCreated();
    $this->actingAs($this->author)
        ->postJson(route('media.store'), ['file' => $file(760, 'quay.jpg')])
        ->assertCreated();

    expect(Media::query()->orderBy('original_filename')->pluck('uploaded_for', 'original_filename')->all())
        ->toBe(['ferry.jpg' => null, 'harbour.jpg' => $this->draft->id, 'quay.jpg' => null]);
});

it('records the item a pasted image is imported for, and nothing on the library’s own copy when it comes again (I-453)', function (): void {
    Sleep::fake();
    dlServe(['photos.example' => ['93.184.216.34']], [
        // A closure: each download needs a body of its own.
        'photos.example/harbour.jpg' => fn () => Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $other = lifecycleDraft($this->author, 'Ferry times');

    $this->actingAs($this->author)
        ->postJson(route('media.from-address'), ['address' => 'https://photos.example/harbour.jpg', 'uploaded_for' => $this->draft->id])
        ->assertCreated();
    $media = Media::query()->sole();

    $again = app(DownloadImage::class)->execute(new DownloadImageInput('https://photos.example/harbour.jpg', (string) $this->author->id, $other->id));

    expect($media->uploaded_for)->toBe($this->draft->id)
        ->and($again)->toBe(['mediaId' => $media->id, 'created' => false])
        ->and($media->fresh()?->uploaded_for)->toBe($this->draft->id);
});

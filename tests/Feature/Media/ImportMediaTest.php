<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Media\Jobs\DeleteMediaFiles;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\AttachImportedMedia;
use App\Domain\Media\Operations\ImportMedia;
use App\Domain\Media\Operations\Input\AttachImportedMediaInput;
use App\Domain\Media\Operations\Input\ImportMediaInput;
use App\Domain\Media\Operations\Input\UpdateImportedMediaInput;
use App\Domain\Media\Operations\UpdateImportedMedia;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Queue::fake();
    $this->importer = User::factory()->create()->assignRole('administrator');
    $this->photo = mediaJpeg(640, 480);
});

/**
 * A copy of a file in the temporary folder, as a download leaves one.
 */
function imoCopy(string $file): string
{
    $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.jpg';
    copy($file, $copy);

    return $copy;
}

/**
 * ImportMedia for a copy of this file, as $actor, or as the operator (at
 * the server's shell unless $shell is false), with these details changed.
 *
 * @param  array<string, mixed>  $details
 *
 * @return array{mediaId: string, created: bool, written: array{title: string, alt: string, caption: string, file: string}|null}
 */
function imoImport(string $file, ?User $actor, array $details = [], bool $shell = true): array
{
    $input = new ImportMediaInput(...[
        'path' => imoCopy($file),
        'filename' => 'harbour.jpg',
        'sourceUrl' => 'https://old.example/wp-content/uploads/2024/05/harbour.jpg',
        'title' => 'Harbour at dawn',
        'alt' => 'Boats moored in a grey harbour',
        'caption' => 'The harbour, May 2024.',
        'uploadedBy' => null,
        'uploadedAt' => CarbonImmutable::parse('2024-05-06 07:08:09', 'UTC'),
        'actorId' => $actor === null ? null : (string) $actor->id,
        ...$details,
    ]);
    $run = fn (): array => app(ImportMedia::class)->execute($input);

    return $actor === null && $shell ? scElevated($run) : $run();
}

/**
 * UpdateImportedMedia for an item, with WordPress's values as imoImport()
 * gives them unless $details change them, and no new file.
 *
 * @param  array<string, mixed>  $written
 * @param  array<string, mixed>  $details
 *
 * @return array{written: array{title: string, alt: string, caption: string, file: string}, kept: list<string>, replaced: bool, refused: bool}
 */
function imoUpdate(string $mediaId, array $written, User $actor, array $details = []): array
{
    return app(UpdateImportedMedia::class)->execute(new UpdateImportedMediaInput(...[
        'mediaId' => $mediaId,
        'written' => $written,
        'title' => 'Harbour at dawn',
        'alt' => 'Boats moored in a grey harbour',
        'caption' => 'The harbour, May 2024.',
        'path' => null,
        'filename' => null,
        'sourceUrl' => null,
        'actorId' => (string) $actor->id,
        ...$details,
    ]));
}

it('stores a downloaded file as a new item with WordPress’s title, alt text, caption, uploader, date and source (I-338)', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $result = imoImport($this->photo, $this->importer, ['uploadedBy' => (string) $author->id]);
    $media = Media::query()->findOrFail($result['mediaId']);

    expect($result['created'])->toBeTrue()
        ->and($media->title)->toBe('Harbour at dawn')
        ->and($media->alt)->toBe('Boats moored in a grey harbour')
        ->and($media->caption)->toBe('The harbour, May 2024.')
        ->and($media->uploaded_by)->toBe($author->id)
        ->and(CarbonImmutable::parse((string) $media->getRawOriginal('created_at'))->utc()->format('Y-m-d H:i:s'))->toBe('2024-05-06 07:08:09')
        ->and($media->source_url)->toBe('https://old.example/wp-content/uploads/2024/05/harbour.jpg')
        ->and($media->original_filename)->toBe('harbour.jpg')
        ->and($result['written'])->toBe([
            'title' => sha1('Harbour at dawn'),
            'alt' => sha1('Boats moored in a grey harbour'),
            'caption' => sha1('The harbour, May 2024.'),
            'file' => hash_file('sha256', $this->photo),
        ])
        ->and(MediaDisks::forOriginal('image')->exists($media->original_path))->toBeTrue();

    Queue::assertPushed(GenerateMediaVariants::class, fn (GenerateMediaVariants $job): bool => $job->mediaId === $media->id);
});

it('imports as the operator at the server’s shell, with no uploader when WordPress’s author isn’t here', function (): void {
    $result = imoImport($this->photo, null);

    expect(Media::query()->findOrFail($result['mediaId'])->uploaded_by)->toBeNull();
});

it('uses the item that already holds the same bytes, whoever made it, writing nothing to it (I-338)', function (string $who): void {
    $existing = $who === 'a person'
        ? mediaUpload($this->importer, imoCopy($this->photo))
        : Media::query()->findOrFail(imoImport($this->photo, $this->importer, ['title' => 'First'])['mediaId']);
    $before = $existing->fresh()?->toArray();

    $result = imoImport($this->photo, $this->importer, ['title' => 'Second', 'alt' => 'Another alt text']);

    expect($result)->toBe(['mediaId' => $existing->id, 'created' => false, 'written' => null])
        ->and(Media::query()->count())->toBe(1)
        ->and($existing->fresh()?->toArray())->toBe($before);
})->with(['a person', 'an earlier import']);

it('leaves no stored file when the object’s transaction rolls back after it (I-338)', function (): void {
    $stored = null;

    $attempt = function () use (&$stored): void {
        DB::transaction(function () use (&$stored): void {
            $stored = Media::query()->findOrFail(imoImport($this->photo, $this->importer)['mediaId'])->original_path;

            throw new RuntimeException('The map row failed.');
        });
    };

    expect($attempt)->toThrow(RuntimeException::class, 'The map row failed.');

    expect($stored)->toBeString()
        ->and(Media::query()->count())->toBe(0)
        ->and(MediaDisks::forOriginal('image')->exists((string) $stored))->toBeFalse();
});

it('refuses what the library can’t keep, writing nothing', function (string $case, string $key): void {
    $details = match ($case) {
        'no file' => ['path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-missing-'.Str::random(8).'.jpg'],
        'a long title' => ['title' => str_repeat('a', 256)],
        'long alt text' => ['alt' => str_repeat('a', 1001)],
        default => ['caption' => str_repeat('a', 2001)],
    };

    expect(fn () => imoImport($this->photo, $this->importer, $details))
        ->toThrow(fn (ValidationException $refused) => expect($refused->errors())->toHaveKey($key))
        ->and(Media::query()->count())->toBe(0);
})->with([
    ['no file', 'file'],
    ['a long title', 'title'],
    ['long alt text', 'alt'],
    ['a long caption', 'caption'],
]);

it('measures its limits in characters, never bytes', function (): void {
    $result = imoImport($this->photo, $this->importer, ['title' => str_repeat('é', 255), 'alt' => str_repeat('é', 1000), 'caption' => str_repeat('é', 2000)]);
    $media = Media::query()->findOrFail($result['mediaId']);

    expect(mb_strlen($media->title))->toBe(255)
        ->and(mb_strlen((string) $media->alt))->toBe(1000)
        ->and(mb_strlen((string) $media->caption))->toBe(2000);
});

it('writes nothing when the actor may not import', function (string $who): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => imoImport($this->photo, $who === 'an editor' ? $editor : null, shell: false))->toThrow(AuthorizationException::class)
        ->and(Media::query()->count())->toBe(0);

    Queue::assertNotPushed(GenerateMediaVariants::class);
})->with(['an editor', 'no one, away from the operator’s shell']);

it('applies WordPress’s new title, alt text and caption where nobody changed them here, and keeps a person’s change, saying which', function (): void {
    $imported = imoImport($this->photo, $this->importer);
    DB::table('media')->where('id', $imported['mediaId'])->update(['alt' => 'A person wrote this alt text']);

    $result = imoUpdate($imported['mediaId'], $imported['written'], $this->importer, ['title' => 'Harbour at noon', 'alt' => 'WordPress’s new alt text', 'caption' => 'Noon.']);
    $media = Media::query()->findOrFail($imported['mediaId']);

    expect($media->title)->toBe('Harbour at noon')
        ->and($media->alt)->toBe('A person wrote this alt text')
        ->and($media->caption)->toBe('Noon.')
        ->and($result['kept'])->toBe(['alt'])
        ->and($result['replaced'])->toBeFalse()
        ->and($result['written'])->toBe([
            'title' => sha1('Harbour at noon'),
            'alt' => $imported['written']['alt'],
            'caption' => sha1('Noon.'),
            'file' => $imported['written']['file'],
        ]);

    // The next run compares with what the import wrote, so it keeps the person's alt text again.
    $again = imoUpdate($imported['mediaId'], $result['written'], $this->importer, ['title' => 'Harbour at noon', 'alt' => 'WordPress’s newer alt text', 'caption' => 'Noon.']);

    expect($again['kept'])->toBe(['alt'])
        ->and($media->fresh()?->alt)->toBe('A person wrote this alt text');
});

it('replaces the file when WordPress’s changed, keeping the item, with a new version and new sizes (I-338)', function (): void {
    $imported = imoImport($this->photo, $this->importer);
    $edited = mediaJpeg(800, 600);

    $result = imoUpdate($imported['mediaId'], $imported['written'], $this->importer, [
        'path' => imoCopy($edited),
        'filename' => 'harbour-e1717000000000.jpg',
        'sourceUrl' => 'https://old.example/wp-content/uploads/2024/05/harbour-e1717000000000.jpg',
    ]);
    $media = Media::query()->findOrFail($imported['mediaId']);

    expect($result['replaced'])->toBeTrue()
        ->and($result['kept'])->toBe([])
        ->and($media->file_version)->toBe(2)
        ->and([$media->width, $media->height])->toBe([800, 600])
        ->and($media->sha256)->toBe(hash_file('sha256', $edited))
        ->and($media->source_url)->toBe('https://old.example/wp-content/uploads/2024/05/harbour-e1717000000000.jpg')
        ->and($result['written']['file'])->toBe($media->sha256)
        ->and(MediaDisks::forOriginal('image')->exists($media->original_path))->toBeTrue()
        ->and(Media::query()->count())->toBe(1);

    Queue::assertPushed(DeleteMediaFiles::class);
    Queue::assertPushed(GenerateMediaVariants::class, fn (GenerateMediaVariants $job): bool => $job->mediaId === $media->id && $job->fileVersion === 2);
});

it('keeps a file a person replaced here since the last import', function (): void {
    $imported = imoImport($this->photo, $this->importer);
    DB::table('media')->where('id', $imported['mediaId'])->update(['sha256' => str_repeat('a', 64)]);

    $result = imoUpdate($imported['mediaId'], $imported['written'], $this->importer, [
        'path' => imoCopy(mediaJpeg(800, 600)),
        'filename' => 'harbour-e1717000000000.jpg',
        'sourceUrl' => 'https://old.example/wp-content/uploads/2024/05/harbour-e1717000000000.jpg',
    ]);

    expect($result['kept'])->toBe(['file'])
        ->and($result['replaced'])->toBeFalse()
        ->and($result['written']['file'])->toBe($imported['written']['file'])
        ->and(Media::query()->findOrFail($imported['mediaId'])->file_version)->toBe(1);

    Queue::assertNotPushed(DeleteMediaFiles::class);
});

it('writes nothing to an item it may not change', function (string $case): void {
    $imported = imoImport($this->photo, $this->importer);
    $editor = User::factory()->create()->assignRole('editor');

    if ($case === 'in Teme’s trash') {
        DB::table('media')->where('id', $imported['mediaId'])->update(['deleted_at' => now()]);
    }

    $before = Media::withTrashed()->findOrFail($imported['mediaId'])->toArray();
    $attempt = fn (): array => imoUpdate(
        $imported['mediaId'],
        $case === 'an unreadable record' ? ['title' => sha1('x')] : $imported['written'],
        $case === 'an editor' ? $editor : $this->importer,
        ['title' => 'Changed'],
    );

    expect($attempt)->toThrow($case === 'an editor' ? AuthorizationException::class : ValidationException::class)
        ->and(Media::withTrashed()->findOrFail($imported['mediaId'])->toArray())->toBe($before);
})->with(['an editor', 'in Teme’s trash', 'an unreadable record']);

it('sets the item a file was uploaded for once, never overwriting it (I-553)', function (): void {
    $mediaId = imoImport($this->photo, $this->importer)['mediaId'];
    [$first, $second] = [Content::factory()->create(), Content::factory()->create()];
    $attach = fn (Content $item): bool => app(AttachImportedMedia::class)->execute(new AttachImportedMediaInput($mediaId, $item->id, (string) $this->importer->id));

    expect($attach($first))->toBeTrue()
        ->and($attach($second))->toBeFalse()
        ->and(Media::query()->findOrFail($mediaId)->uploaded_for)->toBe($first->id);
});

it('attaches nothing when the actor may not import, or the post is gone', function (string $case): void {
    $mediaId = imoImport($this->photo, $this->importer)['mediaId'];
    $item = Content::factory()->create();
    $editor = User::factory()->create()->assignRole('editor');

    if ($case === 'a post deleted since') {
        DB::table('contents')->where('id', $item->id)->delete();
    }

    expect(fn (): bool => app(AttachImportedMedia::class)->execute(new AttachImportedMediaInput($mediaId, $item->id, (string) ($case === 'an editor' ? $editor : $this->importer)->id)))
        ->toThrow($case === 'an editor' ? AuthorizationException::class : ValidationException::class)
        ->and(Media::query()->findOrFail($mediaId)->uploaded_for)->toBeNull();
})->with(['an editor', 'a post deleted since']);

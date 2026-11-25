<?php

declare(strict_types=1);

use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Operations\Input\RecordMediaFilesInput;
use App\Domain\Import\Operations\Input\RecordOutsideImageInput;
use App\Domain\Import\Operations\RecordMediaFiles;
use App\Domain\Import\Operations\RecordOutsideImage;
use App\Domain\Import\Phases\Media\ImportedMedia;
use App\Domain\Import\Phases\Media\WordPressFiles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->importer = User::factory()->create()->assignRole('administrator');
    $this->source = ImportRecords::source();
    $this->run = ImportRecords::run($this->source);
    $this->harbour = mediaUpload($this->importer, mediaJpeg(640, 480), 'harbour.jpg');
    $this->boat = mediaUpload($this->importer, mediaJpeg(320, 240), 'boat.jpg');
});

/**
 * An attachment as the companion sends it: its file, its metadata with
 * these keys, and its backup sizes.
 *
 * @param  array<string, mixed>  $metadata
 * @param  array<string, array{file: string}>|null  $backups
 *
 * @return array<string, mixed>
 */
function imfItem(string $file, array $metadata = [], ?array $backups = null): array
{
    return ['id' => 12, 'file' => $file, 'metadata' => ['file' => $file, 'sizes' => [], ...$metadata], 'backup_sizes' => $backups];
}

/**
 * RecordMediaFiles as $actor.
 *
 * @param  list<string>  $keys
 *
 * @return list<string>
 */
function imfRecord(string $sourceId, int $attachmentId, array $keys, string $mediaId, User $actor): array
{
    return app(RecordMediaFiles::class)->execute(new RecordMediaFilesInput($sourceId, $attachmentId, $keys, $mediaId, (string) $actor->id));
}

it('keeps one key for each file no other key of the attachment reaches (I-338)', function (array $item, array $keys): void {
    expect(WordPressFiles::keys($item))->toBe($keys);
})->with([
    'a large image WordPress scaled' => [
        imfItem('2024/05/Harbour-scaled.jpg', ['original_image' => 'Harbour.jpg', 'sizes' => ['thumbnail' => ['file' => 'Harbour-150x150.jpg'], 'large' => ['file' => 'Harbour-1024x683.jpg']]]),
        ['2024/05/harbour.jpg'],
    ],
    'an image as it was uploaded' => [
        imfItem('2023/01/boat.png', ['sizes' => ['thumbnail' => ['file' => 'boat-150x150.png']]]),
        ['2023/01/boat.png'],
    ],
    'an image edited in WordPress' => [
        imfItem('2023/01/boat-e1700000000000.png', ['sizes' => ['thumbnail' => ['file' => 'boat-e1700000000000-150x150.png']]], ['full-orig' => ['file' => 'boat.png'], 'thumbnail-orig' => ['file' => 'boat-150x150.png']]),
        ['2023/01/boat-e1700000000000.png', '2023/01/boat.png'],
    ],
    'sizes WordPress wrote as WebP' => [
        imfItem('2024/05/cat.jpg', ['sizes' => ['medium' => ['file' => 'cat-300x200.webp'], 'large' => ['file' => 'cat-768x512.webp']]]),
        ['2024/05/cat.jpg', '2024/05/cat-300x200.webp', '2024/05/cat-768x512.webp'],
    ],
    'a path from before WordPress 2.7' => [imfItem('/var/www/site/wp-content/uploads/2007/03/Old.gif'), ['2007/03/old.gif']],
    'no file at all' => [imfItem(''), []],
]);

it('makes the file map’s keys exactly as an article’s address is read: decoded, ASCII letters lower-cased, characters counted', function (): void {
    $site = new ConversionSite('https://old.example');
    $keys = WordPressFiles::keys(imfItem('2024/05/Ünïcode Photo-Ä.JPG', ['sizes' => ['medium' => ['file' => 'Ünïcode Photo-Ä-300x200.JPG']]]));
    $sized = (string) $site->uploadsKey('https://old.example/wp-content/uploads/2024/05/%C3%9Cn%C3%AFcode%20Photo-%C3%84-300x200.JPG');

    expect($keys)->toBe(['2024/05/Ünïcode photo-Ä.jpg'])
        ->and($site->uploadsKey('https://old.example/wp-content/uploads/2024/05/%C3%9Cn%C3%AFcode%20Photo-%C3%84.JPG'))->toBe($keys[0])
        ->and(WordPressFiles::reaches($sized))->toContain($keys[0])
        ->and(WordPressFiles::keys(imfItem('2024/05/'.str_repeat('é', 488).'.jpg')))->toBe(['2024/05/'.str_repeat('é', 488).'.jpg'])
        ->and(WordPressFiles::keys(imfItem('2024/05/'.str_repeat('é', 489).'.jpg')))->toBe([])
        ->and(WordPressFiles::keys(imfItem("2024/05/broken-\xFF.jpg")))->toBe([]);
});

it('records each file address once, leaving one another item holds to it (I-338)', function (): void {
    expect(imfRecord($this->source, 12, ['2024/05/harbour.jpg', '2024/05/shared.jpg'], $this->harbour->id, $this->importer))->toBe([])
        ->and(imfRecord($this->source, 13, ['2024/05/shared.jpg', '2024/05/boat.jpg'], $this->boat->id, $this->importer))->toBe(['2024/05/shared.jpg'])
        ->and(imfRecord($this->source, 12, ['2024/05/harbour.jpg'], $this->harbour->id, $this->importer))->toBe([]);

    $rows = ImportMapEntry::query()->where('object_type', 'file')->orderBy('object_id')->get();

    expect($rows->map(fn (ImportMapEntry $row): array => [$row->object_id, $row->teme_type, $row->teme_id, $row->run_id, $row->status->value, $row->facts])->all())->toBe([
        ['2024/05/boat.jpg', 'media', $this->boat->id, null, 'imported', ['attachment' => 13]],
        ['2024/05/harbour.jpg', 'media', $this->harbour->id, null, 'imported', ['attachment' => 12]],
        ['2024/05/shared.jpg', 'media', $this->harbour->id, null, 'imported', ['attachment' => 12]],
    ]);
});

it('inserts an attachment’s file addresses in one order whatever order they come in, so two lanes never wait on each other (#701)', function (): void {
    $inserted = [];
    DB::listen(function ($query) use (&$inserted): void {
        if (str_starts_with($query->sql, 'insert into "import_map"')) {
            $inserted[] = array_values(array_filter($query->bindings, fn (mixed $binding): bool => is_string($binding) && str_starts_with($binding, '2024/05/')));
        }
    });

    imfRecord($this->source, 12, ['2024/05/harbour.jpg', '2024/05/boat.jpg', '2024/05/anchor.jpg'], $this->harbour->id, $this->importer);

    expect($inserted)->toBe([['2024/05/anchor.jpg', '2024/05/boat.jpg', '2024/05/harbour.jpg']]);
});

it('records no file address when the actor may not import', function (string $who): void {
    $editor = User::factory()->create()->assignRole('editor');
    $record = fn (): array => app(RecordMediaFiles::class)->execute(new RecordMediaFilesInput($this->source, 12, ['2024/05/harbour.jpg'], $this->harbour->id, $who === 'an editor' ? (string) $editor->id : null));

    expect($record)->toThrow(AuthorizationException::class)
        ->and(ImportMapEntry::query()->where('object_type', 'file')->count())->toBe(0);
})->with(['an editor', 'no one, away from the operator’s shell']);

it('refuses a file address the map can’t keep, or an item that’s gone, writing nothing', function (): void {
    expect(fn (): array => imfRecord($this->source, 12, [str_repeat('é', 501)], $this->harbour->id, $this->importer))->toThrow(ValidationException::class)
        ->and(fn (): array => imfRecord($this->source, 12, ['2024/05/harbour.jpg'], '01JVM0000000000000000000AA', $this->importer))->toThrow(ValidationException::class)
        ->and(ImportMapEntry::query()->count())->toBe(0);
});

it('finds an item from any address of any size or edited copy, an address’s own key first (I-338)', function (): void {
    imfRecord($this->source, 12, WordPressFiles::keys(imfItem('2024/05/harbour-scaled.jpg', ['original_image' => 'harbour.jpg'])), $this->harbour->id, $this->importer);
    imfRecord($this->source, 13, ['2024/05/harbour-300x200.jpg'], $this->boat->id, $this->importer);
    $media = new ImportedMedia($this->source);

    expect($media->file('2024/05/harbour.jpg'))->toBe($this->harbour->id)
        ->and($media->file('2024/05/harbour-scaled.jpg'))->toBe($this->harbour->id)
        ->and($media->file('2024/05/harbour-1024x683.jpg'))->toBe($this->harbour->id)
        ->and($media->file('2024/05/harbour-scaled-150x150.jpg'))->toBe($this->harbour->id)
        ->and($media->file('2024/05/harbour-300x200.jpg'))->toBe($this->boat->id)
        ->and($media->file('2024/05/elsewhere.jpg'))->toBeNull()
        ->and($media->file("2024/05/broken-\xFF.jpg"))->toBeNull()
        ->and((new ImportedMedia(ImportRecords::source(['address' => 'https://other.example'])))->file('2024/05/harbour.jpg'))->toBeNull();
});

it('finds an attachment’s item only while it is still here, in the trash too', function (): void {
    foreach ([12 => $this->harbour->id, 13 => $this->boat->id, 14 => '01JVM0000000000000000000AA'] as $id => $mediaId) {
        ImportRecords::entry($this->source, $this->run, 'attachment', (string) $id, 'created', ['teme_type' => 'media', 'teme_id' => $mediaId]);
    }

    DB::table('media')->where('id', $this->boat->id)->update(['deleted_at' => now()]);
    $media = new ImportedMedia($this->source);

    expect($media->attachment(12))->toBe($this->harbour->id)
        ->and($media->attachment(13))->toBe($this->boat->id)
        ->and($media->attachment(14))->toBeNull()
        ->and($media->attachment(15))->toBeNull();
});

it('lists the attachments uploaded to a WordPress post in WordPress’s ID order, by kind, for a gallery or playlist without IDs', function (): void {
    $third = mediaUpload($this->importer, mediaJpeg(200, 100), 'third.jpg');
    $menu = mediaUpload($this->importer, mediaPdf(), 'menu.pdf');
    $attachments = [
        [100, $this->boat->id, 7], [9, $this->harbour->id, 7], [12, $third->id, 8], [10, $this->boat->id, 7],
        [11, $menu->id, 7], [13, '01JVM0000000000000000000AA', 7],
    ];

    foreach ($attachments as [$id, $mediaId, $parent]) {
        ImportRecords::entry($this->source, $this->run, 'attachment', (string) $id, 'created', ['teme_type' => 'media', 'teme_id' => $mediaId, 'facts' => json_encode(['parent' => $parent])]);
    }

    $media = new ImportedMedia($this->source);

    expect($media->attachedTo(7))->toBe([9, 10, 11, 100])
        ->and($media->attachedTo(7, 'image'))->toBe([9, 10, 100])
        ->and($media->attachedTo(7, 'audio'))->toBe([])
        ->and($media->attachedTo(99))->toBe([]);
});

it('keys an outside image by its host and path, as any of its addresses gives them, its query only where the path names no image file (I-1703)', function (string $address, ?string $key): void {
    expect(ImportedMedia::outsideKey($address))->toBe($key);
})->with([
    ['https://cdn.partner.example/Photos/Harbour%20One.JPG?w=300', 'cdn.partner.example/photos/harbour one.jpg'],
    ['//www.CDN.partner.example/Photos/Harbour%20One.JPG', 'cdn.partner.example/photos/harbour one.jpg'],
    ['http://cdn.partner.example/photos/harbour one.jpg#top', 'cdn.partner.example/photos/harbour one.jpg'],
    ['https://cdn.partner.example/photo.php?id=1', 'cdn.partner.example/photo.php?id=1'],
    ['https://cdn.partner.example/image?ID=2&w=300#top', 'cdn.partner.example/image?ID=2&w=300'],
    ['https://ada:secret@cdn.partner.example/photos/harbour.jpg', null],
    ['data:image/png;base64,AAAA', null],
    ['/wp-content/uploads/own.jpg', null],
    ['https://cdn.partner.example/'.str_repeat('a', 500).'.jpg', null],
]);

it('records an outside image under its key, with the run, failed first and imported later (I-1703)', function (): void {
    $record = fn (ImportOutcome $outcome, ?string $mediaId): mixed => app(RecordOutsideImage::class)->execute(
        new RecordOutsideImageInput($this->source, $this->run, 'cdn.partner.example/photos/harbour.jpg', $outcome, $mediaId, 'cdn.partner.example', (string) $this->importer->id),
    );

    $record(ImportOutcome::Failed, null);
    $row = ImportMapEntry::query()->where('object_type', 'outside-image')->sole();

    expect([$row->status->value, $row->teme_id, $row->run_id, $row->facts])->toBe(['failed', null, $this->run, ['host' => 'cdn.partner.example']]);

    $record(ImportOutcome::Created, $this->harbour->id);
    $row = ImportMapEntry::query()->where('object_type', 'outside-image')->sole();

    expect([$row->status->value, $row->outcome->value, $row->teme_type, $row->teme_id])->toBe(['imported', 'created', 'media', $this->harbour->id])
        ->and((new ImportedMedia($this->source))->outside('https://www.cdn.partner.example/photos/harbour.jpg?w=640'))->toBe($this->harbour->id)
        ->and((new ImportedMedia($this->source))->outside('https://cdn.partner.example/photos/other.jpg'))->toBeNull();
});

it('records no outside image when the actor may not import, or the row wouldn’t hold', function (string $case): void {
    $editor = User::factory()->create()->assignRole('editor');
    [$key, $outcome, $mediaId, $actor] = match ($case) {
        'an editor' => ['cdn.partner.example/a.jpg', ImportOutcome::Created, $this->harbour->id, $editor],
        'imported, with no item' => ['cdn.partner.example/a.jpg', ImportOutcome::Created, null, $this->importer],
        default => [str_repeat('a', 501), ImportOutcome::Skipped, null, $this->importer],
    };

    expect(fn (): mixed => app(RecordOutsideImage::class)->execute(new RecordOutsideImageInput($this->source, $this->run, $key, $outcome, $mediaId, 'cdn.partner.example', (string) $actor->id)))
        ->toThrow($case === 'an editor' ? AuthorizationException::class : ValidationException::class)
        ->and(ImportMapEntry::query()->where('object_type', 'outside-image')->count())->toBe(0);
})->with(['an editor', 'imported, with no item', 'a key too long']);

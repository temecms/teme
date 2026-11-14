<?php

declare(strict_types=1);

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Jobs\ImportListPage;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Phases\Finish\DeletedStep;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Media\ImportedMedia;
use App\Domain\Import\Phases\Media\MediaParentsStep;
use App\Domain\Import\Phases\Media\MediaPhase;
use App\Domain\Import\Phases\Media\OutsideImagesStep;
use App\Domain\Media\Downloads\FileDownloader;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request as ApiRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    $this->harbour = dlJpeg(640, 480);
    $this->boat = dlJpeg(320, 240);
    $this->files = [];

    // A folder of this test's own, so what a download leaves behind can be counted.
    $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-imports-'.Str::random(10);
    mkdir($this->folder);
    app()->when(FileDownloader::class)->needs('$folder')->give($this->folder);

    $this->companion = impServe($this->files);
    $this->source = importSource();
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->folder.DIRECTORY_SEPARATOR.'*') ?: []);
    rmdir($this->folder);
});

/**
 * The old site's files, by the path a request names (percent-decoded): its
 * bytes, an HTTP status, or 0 for a site that never answers; answered
 * ahead of the fake companion, which importFake() puts behind them. The
 * array is read at each request, so a test can change it between runs.
 *
 * @param  array<string, string|int>  $files
 */
function impServe(array &$files): FakeCompanion
{
    Http::fake(function (ApiRequest $request) use (&$files) {
        $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

        if (! array_key_exists($path, $files)) {
            return null;
        }

        return match (true) {
            $files[$path] === 0 => Http::failedConnection()($request),
            is_int($files[$path]) => Http::response('', $files[$path]),
            default => Http::response($files[$path], 200),
        };
    });

    return importFake();
}

/**
 * One attachment as the companion's media endpoint sends it: an image
 * WordPress scaled, uploaded to post 7 by WordPress's user 3, but for
 * $fields. Its files live at /wp-content/uploads/2024/05/{name}.jpg and
 * {name}-scaled.jpg.
 *
 * @param  array<string, mixed>  $fields
 *
 * @return array<string, mixed>
 */
function impAttachment(int $id, string $name = 'Harbour One', array $fields = []): array
{
    $base = 'https://old.example/wp-content/uploads/2024/05/'.rawurlencode($name);

    return [
        'id' => $id,
        'parent' => 7,
        'author' => 3,
        'date_gmt' => '2024-05-06 07:08:09',
        'modified_gmt' => '2024-05-06 07:08:09',
        'status' => 'inherit',
        'title' => 'Harbour at dawn',
        'caption' => '<em>The harbour</em>, May 2024.',
        'description' => '',
        'alt' => 'Boats moored in a grey harbour',
        'mime' => 'image/jpeg',
        'file' => "2024/05/{$name}-scaled.jpg",
        'url' => "{$base}-scaled.jpg",
        'original_url' => "{$base}.jpg",
        'attachment_page' => "https://old.example/2024/05/06/harbour/harbour-{$id}/",
        'metadata' => [
            'width' => 2560, 'height' => 1707, 'file' => "2024/05/{$name}-scaled.jpg", 'filesize' => 1000, 'original_image' => "{$name}.jpg",
            'sizes' => ['thumbnail' => ['file' => "{$name}-150x150.jpg", 'width' => 150, 'height' => 150, 'mime' => 'image/jpeg']],
        ],
        'backup_sizes' => null,
        ...$fields,
    ];
}

/**
 * The media phase alone, run to its end over these attachments as the
 * operator, a full import unless $kind says otherwise.
 *
 * @param  list<array<string, mixed>>  $attachments
 */
function impRun(FakeCompanion $companion, ImportSource $source, array $attachments, ImportRunKind $kind = ImportRunKind::Full): ImportRun
{
    $companion->items('media', $attachments);
    importPhaseAlone(app(MediaPhase::class));

    return importRun($source, kind: $kind);
}

/**
 * A catch-up of the media phase and the deleted step, run to its end as
 * the operator, WordPress's other lists empty.
 *
 * @param  list<array<string, mixed>>  $attachments
 */
function impCatchUp(FakeCompanion $companion, ImportSource $source, array $attachments): ImportRun
{
    $companion->items('media', $attachments)->items('users', [])->items('terms', [])->items('urls', [])->items('comments', [])->items('content', [])
        ->items('menus', ['menus' => [], 'navigation' => []]);
    importPhaseAlone(app(MediaPhase::class), app(DeletedStep::class));

    return importRun($source, kind: ImportRunKind::CatchUp);
}

/**
 * The media phase alone, started over these attachments as the operator
 * with the queue held: the page jobs queued so far, the first page's first.
 *
 * @param  list<array<string, mixed>>  $attachments
 *
 * @return Collection<int, ImportListPage>
 */
function impHeld(FakeCompanion $companion, ImportSource $source, array $attachments): Collection
{
    Queue::fake();
    $companion->items('media', $attachments);
    importPhaseAlone(app(MediaPhase::class));
    scElevated(fn (): array => app(StartImportRun::class)->execute(new StartImportRunInput($source->id, ImportRunKind::Full, ['pause_ms' => 0])));

    return Queue::pushed(ImportListPage::class)->values();
}

/**
 * Attachments 1 to $count, each named Photo {id} with a file of its own on
 * the old site.
 *
 * @param  array<string, string|int>  $files
 *
 * @return list<array<string, mixed>>
 */
function impPhotos(array &$files, int $count): array
{
    $attachments = [];

    foreach (range(1, $count) as $id) {
        $attachments[] = impAttachment($id, "Photo {$id}");
        $files["/wp-content/uploads/2024/05/Photo {$id}.jpg"] = dlJpeg(100 + $id, 100);
    }

    return $attachments;
}

/**
 * The requests made for files on the old site's uploads folder.
 *
 * @return list<string>
 */
function impFileRequests(): array
{
    return Http::recorded(fn (ApiRequest $request): bool => str_contains($request->url(), '/wp-content/uploads/'))
        ->map(fn (array $pair): string => rawurldecode((string) parse_url($pair[0]->url(), PHP_URL_PATH)))
        ->values()
        ->all();
}

/**
 * An attachment's map row, and its messages' codes.
 *
 * @return array{0: ImportMapEntry, 1: list<string>}
 */
function impEntry(ImportSource $source, int $id): array
{
    $entry = ImportMapEntry::query()->where('source_id', $source->id)->where('object_type', 'attachment')->where('object_id', (string) $id)->sole();
    $codes = ImportMessageRecord::query()->where('map_id', $entry->id)->orderBy('code')->pluck('code')->map(fn (mixed $code): string => (string) $code)->all();

    return [$entry, $codes];
}

it('imports each attachment’s original once, with its details, its file addresses and its facts (I-338)', function (): void {
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;

    impRun($this->companion, $this->source, [impAttachment(12)]);
    [$entry, $codes] = impEntry($this->source, 12);
    $media = Media::query()->sole();

    expect([$entry->outcome, $entry->teme_type, $entry->teme_id])->toBe([ImportOutcome::Created, 'media', $media->id])
        ->and($codes)->toBe([])
        ->and([$media->title, $media->alt, $media->caption, $media->uploaded_by])->toBe(['Harbour at dawn', 'Boats moored in a grey harbour', 'The harbour, May 2024.', null])
        ->and($media->sha256)->toBe(hash('sha256', $this->harbour))
        ->and(DB::table('media')->where('id', $media->id)->value('created_at'))->toStartWith('2024-05-06 07:08:09')
        ->and($media->source_url)->toBe('https://old.example/wp-content/uploads/2024/05/Harbour%20One.jpg')
        ->and(collect($entry->facts)->only(['parent', 'page_path', 'source', 'duplicate'])->all())->toEqualCanonicalizing([
            'parent' => 7,
            'page_path' => '2024/05/06/harbour/harbour-12',
            'source' => 'https://old.example/wp-content/uploads/2024/05/Harbour%20One.jpg',
            'duplicate' => false,
        ])
        ->and($entry->facts['written']['file'] ?? null)->toBe($media->sha256)
        ->and(ImportMapEntry::query()->where('object_type', 'file')->pluck('object_id')->all())->toBe(['2024/05/harbour one.jpg'])
        ->and((new ImportedMedia($this->source->id))->file('2024/05/harbour one-150x150.jpg'))->toBe($media->id)
        ->and(impFileRequests())->toBe(['/wp-content/uploads/2024/05/Harbour One.jpg'])
        ->and(glob($this->folder.DIRECTORY_SEPARATOR.'*'))->toBe([]);
});

it('brings WordPress’s scaled copy when the original can’t come, saying why (I-338)', function (string $case): void {
    if ($case === 'the original is gone') {
        $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = 404;
    } else {
        dlMediaLimits(['max_megapixels' => 1]);
        $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = dlJpeg(1600, 1200);
    }

    $this->files['/wp-content/uploads/2024/05/Harbour One-scaled.jpg'] = $this->harbour;

    impRun($this->companion, $this->source, [impAttachment(12)]);
    [$entry, $codes] = impEntry($this->source, 12);

    expect($entry->outcome)->toBe(ImportOutcome::Created)
        ->and($codes)->toBe(['attachment.scaled-copy'])
        ->and(Media::query()->sole()->width)->toBe(640)
        ->and($entry->facts['source'])->toBe('https://old.example/wp-content/uploads/2024/05/Harbour%20One.jpg')
        ->and(glob($this->folder.DIRECTORY_SEPARATOR.'*'))->toBe([]);
})->with(['the original is gone', 'the original has too many pixels']);

it('skips with a warning a file that can never come, writing nothing and leaving nothing behind (I-338)', function (string $case, string $words): void {
    $attachment = match ($case) {
        'gone, with no copy' => impAttachment(12, 'menu', ['mime' => 'application/pdf', 'file' => '2024/05/menu.pdf', 'url' => 'https://old.example/wp-content/uploads/2024/05/menu.pdf', 'original_url' => null, 'metadata' => null]),
        'on a private network' => impAttachment(12, 'x', ['url' => 'http://169.254.169.254/latest/harbour.jpg', 'original_url' => 'http://169.254.169.254/latest/harbour.jpg']),
        default => impAttachment(12),
    };
    $this->files['/wp-content/uploads/2024/05/menu.pdf'] = 404;
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $case === 'a web page' ? '<!doctype html><title>Moved</title>' : "PK\x03\x04 not a picture";
    $this->files['/wp-content/uploads/2024/05/Harbour One-scaled.jpg'] = $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'];

    impRun($this->companion, $this->source, [$attachment]);
    [$entry, $codes] = impEntry($this->source, 12);
    $message = ImportMessageRecord::query()->where('map_id', $entry->id)->sole();

    expect([$entry->outcome, $entry->teme_id])->toBe([ImportOutcome::Skipped, null])
        ->and($codes)->toBe(['attachment.refused'])
        ->and($message->message)->toBe("Its file couldn’t come across: {$words} It wasn’t imported; add the file in Media yourself if you need it.")
        ->and($message->level->value)->toBe('warning')
        ->and(Media::query()->count())->toBe(0)
        ->and(ImportMapEntry::query()->where('object_type', 'file')->count())->toBe(0)
        ->and(Http::recorded(fn (ApiRequest $request): bool => str_contains($request->url(), '169.254.169.254'))->count())->toBe(0)
        ->and(glob($this->folder.DIRECTORY_SEPARATOR.'*'))->toBe([]);
})->with([
    ['gone, with no copy', 'old.example has no file at this address (404).'],
    ['on a private network', '169.254.169.254 is on a private network, so Teme won’t connect to it.'],
    ['a web page', 'This address leads to a web page, not a file.'],
    ['a type the library refuses', 'This file isn’t a type the media library accepts.'],
]);

it('fails an attachment its site didn’t answer, as an error, and the next run brings it (I-1980)', function (): void {
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = 0;

    impRun($this->companion, $this->source, [impAttachment(12)]);
    [$entry, $codes] = impEntry($this->source, 12);

    expect($entry->outcome)->toBe(ImportOutcome::Failed)
        ->and($codes)->toBe(['attachment.download-failed'])
        ->and(ImportMessageRecord::query()->where('map_id', $entry->id)->sole()->level->value)->toBe('error')
        ->and(Media::query()->count())->toBe(0);

    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;
    impRun($this->companion, $this->source, [impAttachment(12)]);

    expect(impEntry($this->source, 12)[0]->outcome)->toBe(ImportOutcome::Created)
        ->and(Media::query()->count())->toBe(1);
});

it('says a full import tries a failed download again; a catch-up that doesn’t fetch it leaves it failed and counts it', function (): void {
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = 0;
    $full = impRun($this->companion, $this->source, [impAttachment(12)]);
    [$entry] = impEntry($this->source, 12);

    expect(ImportMessageRecord::query()->where('map_id', $entry->id)->sole()->message)
        ->toStartWith('Its file couldn’t be downloaded this time: ')
        ->toEndWith(' A full import tries it again.');

    // The site answers now, but WordPress hasn't changed the attachment, so a catch-up doesn't fetch it.
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;
    $this->travel(5)->seconds();
    $catchUp = impCatchUp($this->companion, $this->source, [impAttachment(12)]);
    [$after] = impEntry($this->source, 12);

    expect([$after->outcome, $after->run_id])->toBe([ImportOutcome::Failed, $full->id])
        ->and(Media::query()->count())->toBe(0)
        ->and(ImportMessageRecord::query()->where('run_id', $catchUp->id)->where('code', 'catch-up.not-retried')->sole()->message)
        ->toBe('1 item that failed before wasn’t tried again, since a catch-up only fetches what changed on WordPress. Run a full import to retry it.');

    // A full import tries it again, and brings it.
    $this->travel(5)->seconds();
    impRun($this->companion, $this->source, [impAttachment(12)]);

    expect(impEntry($this->source, 12)[0]->outcome)->toBe(ImportOutcome::Created)
        ->and(Media::query()->count())->toBe(1);
});

it('never counts a failure the catch-up did try: an attachment WordPress changed is fetched, and failing again is the catch-up’s own', function (): void {
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = 0;
    impRun($this->companion, $this->source, [impAttachment(12)]);

    $this->travel(5)->seconds();
    $catchUp = impCatchUp($this->companion, $this->source, [impAttachment(12, fields: ['modified_gmt' => now()->utc()->format('Y-m-d H:i:s')])]);
    [$entry] = impEntry($this->source, 12);

    expect([$entry->outcome, $entry->run_id])->toBe([ImportOutcome::Failed, $catchUp->id])
        ->and(ImportMessageRecord::query()->where('run_id', $catchUp->id)->where('map_id', $entry->id)->pluck('code')->all())->toBe(['attachment.download-failed'])
        ->and(ImportMessageRecord::query()->where('run_id', $catchUp->id)->where('code', 'catch-up.not-retried')->exists())->toBeFalse()
        ->and($catchUp->failed_count)->toBe(1);
});

it('uses the library’s item for a file it already has, and two attachments with one file make one item (I-338)', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.jpg';
    file_put_contents($path, $this->harbour);
    $theirs = mediaUpload($admin, $path, 'Holiday.jpg');
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;
    $this->files['/wp-content/uploads/2024/05/Harbour Copy.jpg'] = $this->harbour;

    impRun($this->companion, $this->source, [impAttachment(12), impAttachment(13, 'Harbour Copy', ['title' => 'Another title'])]);

    foreach ([12, 13] as $id) {
        [$entry, $codes] = impEntry($this->source, $id);

        expect([$entry->outcome, $entry->teme_id, $entry->facts['duplicate']])->toBe([ImportOutcome::Updated, $theirs->id, true])
            ->and($codes)->toBe(['attachment.duplicate']);
    }

    expect(Media::query()->count())->toBe(1)
        ->and($theirs->fresh()?->title)->toBe('Holiday')
        ->and((new ImportedMedia($this->source->id))->file('2024/05/harbour copy-150x150.jpg'))->toBe($theirs->id);
});

it('imports WordPress’s edited file as the attachment’s own item when it used another’s (I-338)', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.jpg';
    file_put_contents($path, $this->harbour);
    $theirs = mediaUpload($admin, $path, 'Holiday.jpg');
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;
    impRun($this->companion, $this->source, [impAttachment(12)]);

    $this->files['/wp-content/uploads/2024/05/Harbour One-e1717000000000.jpg'] = $this->boat;
    impRun($this->companion, $this->source, [impAttachment(12, fields: [
        'file' => '2024/05/Harbour One-e1717000000000.jpg',
        'url' => 'https://old.example/wp-content/uploads/2024/05/Harbour%20One-e1717000000000.jpg',
        'metadata' => ['file' => '2024/05/Harbour One-e1717000000000.jpg', 'original_image' => 'Harbour One.jpg', 'sizes' => []],
    ])]);
    [$entry, $codes] = impEntry($this->source, 12);
    $own = Media::query()->where('sha256', hash('sha256', $this->boat))->sole();
    $media = new ImportedMedia($this->source->id);

    expect([$entry->outcome, $entry->teme_id, $entry->facts['duplicate']])->toBe([ImportOutcome::Created, $own->id, false])
        ->and($codes)->toBe([])
        ->and($own->title)->toBe('Harbour at dawn')
        ->and($theirs->fresh()?->title)->toBe('Holiday')
        ->and(Media::query()->count())->toBe(2)
        ->and($media->file('2024/05/harbour one-e1717000000000.jpg'))->toBe($own->id)
        ->and($media->file('2024/05/harbour one.jpg'))->toBe($theirs->id);
});

it('never downloads an unchanged file again, and a rerun duplicates nothing', function (): void {
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;

    impRun($this->companion, $this->source, [impAttachment(12)]);
    impRun($this->companion, $this->source, [impAttachment(12, fields: ['title' => 'Harbour at dusk'])]);
    impRun($this->companion, $this->source, [impAttachment(12, fields: ['title' => 'Harbour at dusk'])]);

    expect(impFileRequests())->toHaveCount(1)
        ->and(Media::query()->sole()->title)->toBe('Harbour at dusk')
        ->and(ImportMapEntry::query()->where('object_type', 'attachment')->count())->toBe(1)
        ->and(ImportMapEntry::query()->where('object_type', 'file')->count())->toBe(1)
        ->and(impEntry($this->source, 12)[0]->outcome)->toBe(ImportOutcome::Updated);
});

it('follows WordPress’s later changes where nobody changed them here, its edited file too (I-338)', function (): void {
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;
    impRun($this->companion, $this->source, [impAttachment(12)]);
    $media = Media::query()->sole();
    DB::table('media')->where('id', $media->id)->update(['alt' => 'A person wrote this alt text']);

    // As WordPress's image editor leaves it: the main file moved to the edited copy, original_image and so original_url still the unedited upload.
    $this->files['/wp-content/uploads/2024/05/Harbour One-e1717000000000.jpg'] = $this->boat;
    impRun($this->companion, $this->source, [impAttachment(12, fields: [
        'title' => 'Harbour at dusk',
        'alt' => 'WordPress’s new alt text',
        'file' => '2024/05/Harbour One-e1717000000000.jpg',
        'url' => 'https://old.example/wp-content/uploads/2024/05/Harbour%20One-e1717000000000.jpg',
        'original_url' => 'https://old.example/wp-content/uploads/2024/05/Harbour%20One.jpg',
        'metadata' => ['file' => '2024/05/Harbour One-e1717000000000.jpg', 'original_image' => 'Harbour One.jpg', 'sizes' => []],
        'backup_sizes' => ['full-orig' => ['file' => 'Harbour One-scaled.jpg', 'width' => 2560, 'height' => 1707]],
    ])]);
    [$entry, $codes] = impEntry($this->source, 12);
    $fresh = $media->fresh();
    $message = ImportMessageRecord::query()->where('map_id', $entry->id)->sole();

    expect([$fresh?->title, $fresh?->alt, $fresh?->file_version, $fresh?->sha256])->toBe(['Harbour at dusk', 'A person wrote this alt text', 2, hash('sha256', $this->boat)])
        ->and($entry->facts['source'])->toBe('https://old.example/wp-content/uploads/2024/05/Harbour%20One-e1717000000000.jpg')
        ->and(impFileRequests())->toBe(['/wp-content/uploads/2024/05/Harbour One.jpg', '/wp-content/uploads/2024/05/Harbour One-e1717000000000.jpg'])
        ->and($codes)->toBe(['attachment.kept-here'])
        ->and($message->message)->toBe('Its alt text changed in Teme since the last import, so Teme kept it rather than WordPress’s.')
        ->and(Media::query()->count())->toBe(1)
        ->and(ImportMapEntry::query()->where('object_type', 'file')->orderBy('object_id')->pluck('object_id')->all())->toBe(['2024/05/harbour one-e1717000000000.jpg', '2024/05/harbour one.jpg']);
});

it('leaves an item deleted or trashed here as it is', function (string $case, ImportOutcome $outcome, string $code): void {
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;
    impRun($this->companion, $this->source, [impAttachment(12)]);
    $media = Media::query()->sole();

    $case === 'deleted'
        ? DB::table('media')->where('id', $media->id)->delete()
        : DB::table('media')->where('id', $media->id)->update(['deleted_at' => now()]);

    impRun($this->companion, $this->source, [impAttachment(12, fields: ['title' => 'Harbour at dusk'])]);
    [$entry, $codes] = impEntry($this->source, 12);

    expect([$entry->outcome, $entry->teme_id])->toBe([$outcome, $media->id])
        ->and($codes)->toBe([$code])
        ->and(Media::withTrashed()->count())->toBe($case === 'deleted' ? 0 : 1)
        ->and(Media::withTrashed()->find($media->id)?->title)->toBe($case === 'deleted' ? null : 'Harbour at dawn')
        ->and(impFileRequests())->toHaveCount(1);
})->with([
    ['deleted', ImportOutcome::Skipped, 'attachment.deleted-here'],
    ['in the trash', ImportOutcome::Updated, 'attachment.trashed-here'],
]);

it('skips an attachment in WordPress’s trash, asking for nothing', function (): void {
    impRun($this->companion, $this->source, [impAttachment(12, fields: ['status' => 'trash'])]);
    [$entry, $codes] = impEntry($this->source, 12);

    expect($entry->outcome)->toBe(ImportOutcome::Skipped)
        ->and($codes)->toBe(['attachment.trashed'])
        ->and(impFileRequests())->toBe([]);
});

it('cuts what is too long, and says what it can’t keep', function (): void {
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;

    impRun($this->companion, $this->source, [impAttachment(12, fields: [
        'title' => str_repeat('é', 300),
        'alt' => str_repeat('a', 1200),
        'caption' => '<p>'.str_repeat('b', 2500).'</p>',
        'description' => 'A longer story about the harbour.',
    ])]);
    [, $codes] = impEntry($this->source, 12);
    $media = Media::query()->sole();

    expect([mb_strlen($media->title), mb_strlen((string) $media->alt), mb_strlen((string) $media->caption)])->toBe([255, 1000, 2000])
        ->and($codes)->toBe(['attachment.alt-shortened', 'attachment.caption-shortened', 'attachment.description-not-kept', 'attachment.title-shortened']);
});

it('records its uploader when WordPress’s author became a Teme person', function (): void {
    $author = User::factory()->create()->assignRole('author');
    ImportRecords::entry($this->source->id, ImportRecords::run($this->source->id), 'user', '3', 'created', ['teme_type' => 'user', 'teme_id' => (string) $author->id]);
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;

    impRun($this->companion, $this->source, [impAttachment(12)]);

    expect(Media::query()->sole()->uploaded_by)->toBe($author->id);
});

it('reads the media list twenty at a time, and only what changed in a catch-up', function (): void {
    $this->files['/wp-content/uploads/2024/05/Harbour One.jpg'] = $this->harbour;
    impRun($this->companion, $this->source, [impAttachment(12)]);

    // A catch-up reads from the last run that started before it: two runs begun in one second have none.
    $this->travel(5)->seconds();
    impRun($this->companion, $this->source, [impAttachment(12)], ImportRunKind::CatchUp);
    $requests = $this->companion->requestsTo('media');

    expect($requests[0]['query']['limit'])->toBe('20')
        ->and($requests[0]['query'])->not->toHaveKey('modified_after')
        ->and(end($requests)['query'])->toHaveKey('modified_after');
});

it('imports a page of twenty quick attachments in one job (#254)', function (): void {
    $pages = impHeld($this->companion, $this->source, impPhotos($this->files, 25));

    cmRunJob($pages->first());

    expect(ImportMapEntry::query()->where('object_type', 'attachment')->count())->toBe(20)
        ->and($this->companion->requestsTo('media'))->toHaveCount(1)
        ->and(impFileRequests())->toHaveCount(20)
        ->and(Queue::pushed(ImportListPage::class)->last()->afterId)->toBe(20)
        // The slowest attachment a job may start still ends inside the job's time.
        ->and(ImportListPage::SECONDS + MediaPhase::ITEM_SECONDS)->toBeLessThan($pages->first()->timeout);
});

it('leaves the rest of a slow page to the next job, which starts after the last attachment done, so each comes once (#254)', function (): void {
    // Each download takes ten seconds of the job's time.
    Event::listen(RequestSending::class, function (RequestSending $sending): void {
        if (str_contains($sending->request->url(), '/wp-content/uploads/')) {
            $this->travel(10)->seconds();
        }
    });
    impHeld($this->companion, $this->source, impPhotos($this->files, 12));

    for ($ran = 0; ($page = Queue::pushed(ImportListPage::class)->values()->get($ran)) !== null; $ran++) {
        cmRunJob($page);
    }

    expect(Queue::pushed(ImportListPage::class)->map(fn (ImportListPage $page): int => $page->afterId)->values()->all())->toBe([0, 5, 10])
        ->and(ImportMapEntry::query()->where('object_type', 'attachment')->orderByRaw('object_id::int')->pluck('object_id')->all())
        ->toBe(array_map(strval(...), range(1, 12)))
        ->and(impFileRequests())->toBe(array_map(fn (int $id): string => "/wp-content/uploads/2024/05/Photo {$id}.jpg", range(1, 12)))
        ->and(Media::query()->count())->toBe(12);
});

it('imports the fake companion’s own attachments, the PDF beside the image', function (): void {
    $this->files['/wp-content/uploads/2024/05/Harbour.jpg'] = $this->harbour;
    $this->files['/wp-content/uploads/2024/05/menu.pdf'] = (string) file_get_contents(mediaPdf());
    importPhaseAlone(app(MediaPhase::class));

    importRun($this->source);

    expect(Media::query()->orderBy('kind')->pluck('kind')->all())->toBe(['document', 'image'])
        ->and(impEntry($this->source, 13)[0]->facts['page_path'])->toBe('menu');
});

it('registers the media phase at 400, and its two steps at 450 and 590, for every kind of run', function (): void {
    $phases = app(ImportPhases::class);
    $ours = [$phases->find('media'), $phases->find('outside-images'), $phases->find('media-parents')];

    expect(array_map(fn ($phase): ?int => $phase?->order(), $ours))->toBe([400, 450, 590])
        ->and($ours[0])->toBeInstanceOf(MediaPhase::class)
        ->and($ours[1])->toBeInstanceOf(OutsideImagesStep::class)
        ->and($ours[2])->toBeInstanceOf(MediaParentsStep::class)
        ->and(array_map(fn ($phase): bool => (bool) $phase?->runsIn(ImportRunKind::CatchUp), $ours))->toBe([true, true, true]);
});

it('skips with a warning an attachment whose image is damaged, rather than failing on it', function (): void {
    $this->files['/wp-content/uploads/2024/05/Quick-Money1.gif'] = (string) file_get_contents(mediaDamagedGif());

    impRun($this->companion, $this->source, [impAttachment(24662, 'Quick-Money1', [
        'mime' => 'image/gif',
        'file' => '2024/05/Quick-Money1.gif',
        'url' => 'https://old.example/wp-content/uploads/2024/05/Quick-Money1.gif',
        'original_url' => null,
        'metadata' => null,
    ])]);
    [$entry, $codes] = impEntry($this->source, 24662);
    $message = ImportMessageRecord::query()->where('map_id', $entry->id)->sole();

    expect([$entry->outcome, $entry->teme_id])->toBe([ImportOutcome::Skipped, null])
        ->and($codes)->toBe(['attachment.refused'])
        ->and($message->message)->toBe('Its file couldn’t come across: This file looks like an image but can’t be read. It may be damaged. Open it in an image editor, save it again and upload it. It wasn’t imported; add the file in Media yourself if you need it.')
        ->and($message->level->value)->toBe('warning')
        ->and(Media::query()->count())->toBe(0)
        ->and(glob($this->folder.DIRECTORY_SEPARATOR.'*'))->toBe([]);
});

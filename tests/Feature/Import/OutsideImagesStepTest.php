<?php

declare(strict_types=1);

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\AttachUploadsCopy;
use App\Domain\Import\Operations\Input\AttachUploadsCopyInput;
use App\Domain\Import\Phases\Media\ImportedMedia;
use App\Domain\Import\Phases\Media\OutsideImagesStep;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ApiRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Fixtures\Import\FakeCompanion;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    $this->harbour = dlJpeg(640, 480);
    $this->files = [];
    $this->companion = oisServe($this->files);
    importResolve([FakeCompanion::HOST => [FakeCompanion::IP], 'cdn.partner.example' => ['93.184.216.35'], 'photos.other.example' => ['93.184.216.36']]);
    $this->source = importSource();
});

/**
 * Outside sites' files, by host and decoded path
 * (`cdn.partner.example/photos/harbour.jpg`): bytes, an HTTP status, 0 for
 * a site that never answers, or a closure giving bytes; answered ahead of
 * the fake companion, and read at each request.
 *
 * @param  array<string, string|int|Closure>  $files
 */
function oisServe(array &$files): FakeCompanion
{
    Http::fake(function (ApiRequest $request) use (&$files) {
        $key = strtolower((string) parse_url($request->url(), PHP_URL_HOST)).rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

        if (! array_key_exists($key, $files)) {
            return null;
        }

        $file = $files[$key] instanceof Closure ? ($files[$key])() : $files[$key];

        return match (true) {
            $file === 0 => Http::failedConnection()($request),
            is_int($file) => Http::response('', $file),
            default => Http::response($file, 200),
        };
    });

    return importFake();
}

/**
 * A WordPress post as the companion's content list sends it, showing this HTML.
 *
 * @return array<string, mixed>
 */
function oisItem(int $id, string $html): array
{
    return ['id' => $id, 'type' => 'post', 'status' => 'publish', 'content' => $html, 'modified_gmt' => '2024-05-06 07:08:09'];
}

/**
 * The outside images step alone, run to its end over these posts as the
 * operator, these hosts ticked.
 *
 * @param  list<array<string, mixed>>  $items
 * @param  list<string>  $hosts
 */
function oisRun(FakeCompanion $companion, ImportSource $source, array $items, array $hosts = ['cdn.partner.example'], ImportRunKind $kind = ImportRunKind::Full): void
{
    $companion->items('content', $items);
    importPhaseAlone(app(OutsideImagesStep::class));
    importRun($source, kind: $kind, choices: ['outside_images' => $hosts]);
}

/**
 * The runs' own messages' words, by code.
 *
 * @return array<string, list<string>>
 */
function oisSaid(): array
{
    $said = [];

    foreach (ImportMessageRecord::query()->whereNull('map_id')->orderBy('created_at')->orderBy('id')->get() as $message) {
        $said[$message->code][] = $message->message;
    }

    return $said;
}

/**
 * How many requests went to this host.
 */
function oisAsked(string $host): int
{
    return Http::recorded(fn (ApiRequest $request): bool => parse_url($request->url(), PHP_URL_HOST) === $host)->count();
}

it('doesn’t bring another site’s images when none is ticked', function (): void {
    oisRun($this->companion, $this->source, [oisItem(20, '<img src="https://cdn.partner.example/photos/harbour.jpg">')], []);

    expect(Media::query()->count())->toBe(0)
        ->and(oisAsked('cdn.partner.example'))->toBe(0)
        ->and(ImportMapEntry::query()->where('object_type', 'outside-image')->count())->toBe(0);
});

it('brings an own image that isn’t in the media list, with no outside site ticked', function (): void {
    $this->files['old.example/wp-content/uploads/2024/05/Teeth-Whitening.jpg'] = $this->harbour;

    oisRun($this->companion, $this->source, [
        oisItem(20, '<p><img src="https://old.example/wp-content/uploads/2024/05/Teeth-Whitening.jpg" alt="Teeth"></p>'),
    ], []);
    $row = ImportMapEntry::query()->where('object_type', 'outside-image')->sole();
    $media = Media::query()->sole();

    expect([$row->object_id, $row->outcome, $row->teme_id, $row->facts])->toBe([
        'old.example/wp-content/uploads/2024/05/teeth-whitening.jpg',
        ImportOutcome::Created,
        $media->id,
        ['host' => 'old.example', 'picture_from' => 'site'],
    ])
        ->and([$media->title, $media->alt, $media->source_url])->toBe(['Teeth-Whitening', 'Teeth', 'https://old.example/wp-content/uploads/2024/05/Teeth-Whitening.jpg'])
        ->and((new ImportedMedia($this->source->id))->outside('https://old.example/wp-content/uploads/2024/05/Teeth-Whitening.jpg?w=640'))->toBe($media->id)
        ->and(Http::recorded(fn (ApiRequest $request): bool => str_contains($request->url(), 'Teeth-Whitening.jpg'))->count())->toBe(1)
        ->and(oisSaid())->toBe([]);
});

it('brings each image from a ticked site once, keyed by its address, and leaves other sites’ images alone (I-1703)', function (): void {
    $this->files['cdn.partner.example/photos/harbour.jpg'] = $this->harbour;
    $this->files['photos.other.example/boat.jpg'] = dlJpeg(320, 240);

    oisRun($this->companion, $this->source, [
        oisItem(20, '<p><img src="https://cdn.partner.example/photos/harbour.jpg?w=300" alt="The harbour"></p><p><img src="https://photos.other.example/boat.jpg"></p>'),
        oisItem(21, '<!-- wp:image --><figure><img src="//www.cdn.partner.example/photos/harbour.jpg" alt=""></figure><!-- /wp:image -->'),
    ]);
    $row = ImportMapEntry::query()->where('object_type', 'outside-image')->sole();
    $media = Media::query()->sole();

    expect([$row->object_id, $row->outcome, $row->teme_id, $row->facts])->toBe(['cdn.partner.example/photos/harbour.jpg', ImportOutcome::Created, $media->id, ['host' => 'cdn.partner.example', 'picture_from' => 'site']])
        ->and($row->run_id)->toBeString()
        ->and([$media->title, $media->alt, $media->source_url])->toBe(['harbour', 'The harbour', 'https://cdn.partner.example/photos/harbour.jpg'])
        ->and((new ImportedMedia($this->source->id))->outside('https://cdn.partner.example/photos/harbour.jpg?w=1024'))->toBe($media->id)
        ->and(oisAsked('cdn.partner.example'))->toBe(1)
        ->and(oisAsked('photos.other.example'))->toBe(0)
        ->and(oisSaid())->toBe([]);
});

it('reports an image that can never come once, and doesn’t ask for it again', function (): void {
    $this->files['cdn.partner.example/photos/gone.jpg'] = 404;
    $post = oisItem(20, '<img src="https://cdn.partner.example/photos/gone.jpg">');

    oisRun($this->companion, $this->source, [$post]);
    oisRun($this->companion, $this->source, [$post]);

    expect(ImportMapEntry::query()->where('object_type', 'outside-image')->sole()->outcome)->toBe(ImportOutcome::Skipped)
        ->and(oisSaid())->toBe(['outside-image.refused' => ['An image from cdn.partner.example (gone.jpg) couldn’t come across: cdn.partner.example has no file at this address (404). Posts link to it instead.']])
        ->and(oisAsked('cdn.partner.example'))->toBe(1);
});

it('fails an image its site didn’t answer, as an error, and the next run brings it', function (): void {
    $this->files['cdn.partner.example/photos/harbour.jpg'] = 0;
    $posts = [
        oisItem(20, '<img src="https://cdn.partner.example/photos/harbour.jpg">'),
        oisItem(21, '<img src="https://cdn.partner.example/photos/harbour.jpg">'),
    ];

    oisRun($this->companion, $this->source, $posts);

    expect(ImportMapEntry::query()->where('object_type', 'outside-image')->sole()->outcome)->toBe(ImportOutcome::Failed)
        ->and(array_keys(oisSaid()))->toBe(['outside-image.failed'])
        ->and(ImportMessageRecord::query()->where('code', 'outside-image.failed')->sole()->level->value)->toBe('error')
        ->and(oisSaid()['outside-image.failed'][0])->toEndWith(' A full import tries it again.');

    $this->files['cdn.partner.example/photos/harbour.jpg'] = $this->harbour;
    oisRun($this->companion, $this->source, $posts);

    expect(ImportMapEntry::query()->where('object_type', 'outside-image')->sole()->outcome)->toBe(ImportOutcome::Created)
        ->and(Media::query()->count())->toBe(1);
});

it('uses the library’s item for an image it already has, writing nothing to it', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.jpg';
    file_put_contents($path, $this->harbour);
    $theirs = mediaUpload($admin, $path, 'Holiday.jpg');
    $this->files['cdn.partner.example/photos/harbour.jpg'] = $this->harbour;

    oisRun($this->companion, $this->source, [oisItem(20, '<img src="https://cdn.partner.example/photos/harbour.jpg" alt="The harbour">')]);
    $row = ImportMapEntry::query()->where('object_type', 'outside-image')->sole();

    expect([$row->outcome, $row->teme_id])->toBe([ImportOutcome::Updated, $theirs->id])
        ->and(Media::query()->count())->toBe(1)
        ->and($theirs->fresh()?->alt)->toBeNull();
});

it('stops a job after its time and carries on at the last item it finished', function (): void {
    foreach (['a', 'b', 'c'] as $name) {
        $this->files["cdn.partner.example/photos/{$name}.jpg"] = function () use ($name): string {
            $this->travel(30)->seconds();

            return dlJpeg(100 + ord($name), 100);
        };
    }

    oisRun($this->companion, $this->source, [
        oisItem(20, '<img src="https://cdn.partner.example/photos/a.jpg">'),
        oisItem(21, '<img src="https://cdn.partner.example/photos/b.jpg">'),
        oisItem(22, '<img src="https://cdn.partner.example/photos/c.jpg">'),
    ]);

    expect(ImportMapEntry::query()->where('object_type', 'outside-image')->where('outcome', 'created')->count())->toBe(3)
        ->and(array_map(fn (array $request): string => $request['query']['after_id'], $this->companion->requestsTo('content')))->toBe(['0', '21']);
});

it('reads only what changed since the last import in a catch-up', function (): void {
    $this->files['cdn.partner.example/photos/harbour.jpg'] = $this->harbour;
    $post = oisItem(20, '<img src="https://cdn.partner.example/photos/harbour.jpg">');

    oisRun($this->companion, $this->source, [$post]);

    // A catch-up reads from the last run that started before it: two runs begun in one second have none.
    $this->travel(5)->seconds();
    oisRun($this->companion, $this->source, [$post], kind: ImportRunKind::CatchUp);
    $requests = $this->companion->requestsTo('content');

    expect($requests[0]['query'])->not->toHaveKey('modified_after')
        ->and(end($requests)['query'])->toHaveKey('modified_after');
});

it('says once, when its post is finished, that an image address is too long to keep or carries a user name', function (): void {
    foreach (['a', 'b', 'c'] as $name) {
        $this->files["cdn.partner.example/photos/{$name}.jpg"] = function () use ($name): string {
            $this->travel(30)->seconds();

            return dlJpeg(100 + ord($name), 100);
        };
    }

    // The first job stops before c.jpg, inside the post; the second finishes it.
    oisRun($this->companion, $this->source, [oisItem(20, implode('', [
        '<img src="https://cdn.partner.example/'.str_repeat('a', 500).'.jpg">',
        '<img src="https://ada:secret@cdn.partner.example/photos/private.jpg">',
        '<img src="https://cdn.partner.example/photos/a.jpg">',
        '<img src="https://cdn.partner.example/photos/b.jpg">',
        '<img src="https://cdn.partner.example/photos/c.jpg">',
    ]))]);

    expect(oisSaid())->toEqual([
        'outside-image.too-long' => ['An image address from cdn.partner.example is too long for Teme to keep, so posts link to it instead.'],
        'outside-image.credentials' => ['An image address from cdn.partner.example carries a user name, so Teme won’t download it, and posts link to it instead.'],
    ])
        ->and($this->companion->requestsTo('content'))->toHaveCount(2)
        ->and(ImportMapEntry::query()->where('object_type', 'outside-image')->count())->toBe(3)
        ->and(Http::recorded(fn (ApiRequest $request): bool => str_contains($request->url(), 'private.jpg'))->count())->toBe(0);
});

it('refuses an image whose site resolves to a private network, without connecting', function (): void {
    importResolve([FakeCompanion::HOST => [FakeCompanion::IP], 'cdn.partner.example' => ['10.0.0.5']]);

    oisRun($this->companion, $this->source, [oisItem(20, '<img src="https://cdn.partner.example/photos/harbour.jpg">')]);

    expect(ImportMapEntry::query()->where('object_type', 'outside-image')->sole()->outcome)->toBe(ImportOutcome::Skipped)
        ->and(oisSaid())->toBe(['outside-image.refused' => ['An image from cdn.partner.example (harbour.jpg) couldn’t come across: cdn.partner.example is on a private network, so Teme won’t connect to it. Posts link to it instead.']])
        ->and(oisAsked('cdn.partner.example'))->toBe(0);
});

it('brings an own picture from the uploads copy without asking the old site for it', function (): void {
    $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-ois-copy-'.uniqid();
    mkdir($copy.'/2024/05', recursive: true);
    file_put_contents($copy.'/2024/05/Teeth-Whitening.jpg', $this->harbour);
    scElevated(fn (): array => app(AttachUploadsCopy::class)->execute(new AttachUploadsCopyInput($this->source->id, $copy, null)));

    oisRun($this->companion, $this->source, [
        oisItem(20, '<p><img src="https://old.example/wp-content/uploads/2024/05/Teeth-Whitening.jpg" alt="Teeth"></p>'),
    ], []);

    expect(Http::recorded(fn (ApiRequest $request): bool => str_contains($request->url(), 'Teeth-Whitening.jpg'))->count())->toBe(0)
        ->and(ImportMapEntry::query()->where('object_type', 'outside-image')->sole()->facts)->toBe(['host' => 'old.example', 'picture_from' => 'copy'])
        ->and(Media::query()->count())->toBe(1);

    unlink($copy.'/2024/05/Teeth-Whitening.jpg');
    rmdir($copy.'/2024/05');
    rmdir($copy.'/2024');
    rmdir($copy);
});

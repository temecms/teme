<?php

declare(strict_types=1);

use App\Domain\Media\Models\MediaMove;
use App\Domain\Media\Storage\MediaMoveLock;
use App\Domain\Media\Storage\MediaMover;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use Tests\Feature\Media\Storage\FakeBucket;
use Tests\Feature\Media\Storage\StorageEnvironment;
use Tests\Support\Media\CorruptingAdapter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media', ['url' => 'http://localhost/storage/media']);
    Storage::fake('media-originals');
    Storage::fake('media-bucket', ['url' => 'https://cdn.example.test']);
    Storage::fake('media-originals-bucket');
});

afterEach(function (): void {
    if (array_key_exists('mv_other_session', DB::getConnections())) {
        DB::connection('mv_other_session')->select('SELECT pg_advisory_unlock_all()');
        DB::purge('mv_other_session');
    }
});

/**
 * One media row and its files on the server's faked disks. Its ID.
 *
 * @param  list<string>  $variants
 */
function mvItem(string $kind = 'image', array $variants = ['w640', 'thumb'], bool $withFiles = true, ?string $id = null, bool $trashed = false, ?CarbonInterface $updatedAt = null): string
{
    $id ??= (string) Str::ulid();
    $extension = $kind === 'image' ? 'jpg' : 'pdf';
    $original = "{$id}/v1/item.{$extension}";
    $bytes = "original bytes of {$id}";
    $map = [];

    foreach ($variants as $name) {
        $map[$name] = ['path' => "{$id}/v1/item-{$name}.webp", 'width' => 640, 'height' => 427, 'bytes' => strlen("webp {$name} {$id}")];
    }

    DB::table('media')->insert([
        'id' => $id,
        'kind' => $kind,
        'mime_type' => $kind === 'image' ? 'image/jpeg' : 'application/pdf',
        'extension' => $extension,
        'original_filename' => "item.{$extension}",
        'name' => 'item',
        'original_path' => $original,
        'size_bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'title' => 'Item',
        'variants' => json_encode($map === [] ? new stdClass : $map, JSON_THROW_ON_ERROR),
        'processing' => 'ready',
        'created_at' => now(),
        'updated_at' => $updatedAt ?? now(),
        'deleted_at' => $trashed ? now() : null,
    ]);

    if ($withFiles) {
        Storage::disk($kind === 'image' ? 'media-originals' : 'media')->put($original, $bytes);

        foreach ($map as $name => $variant) {
            Storage::disk('media')->put($variant['path'], "webp {$name} {$id}");
        }
    }

    return $id;
}

/**
 * Every file a row names, by disk and path, as the move must leave them.
 *
 * @return array<string, string>
 */
function mvFiles(string $location): array
{
    $disks = config("media.locations.{$location}");
    $files = [];

    foreach (DB::table('media')->orderBy('id')->get() as $row) {
        $kind = (string) $row->kind;
        $original = $kind === 'image' ? $disks['originals'] : $disks['public'];
        $files["{$original}:{$row->original_path}"] = Storage::disk($original)->exists($row->original_path) ? Storage::disk($original)->get($row->original_path) : null;

        foreach ((array) json_decode((string) $row->variants, true) as $variant) {
            $files["{$disks['public']}:{$variant['path']}"] = Storage::disk($disks['public'])->exists($variant['path']) ? Storage::disk($disks['public'])->get($variant['path']) : null;
        }
    }

    return $files;
}

function mvRun(array $options = []): array
{
    $code = Artisan::call('teme:media:move', $options);

    return [$code, Artisan::output()];
}

/**
 * The move, called as the command calls it, stopping after $after items.
 *
 * @return array{code: int, runId: string|null, sentence: string}
 */
function mvStoppedAfter(int $after, int $chunk = 2): array
{
    app()->instance(Elevation::class, new OperatorElevation);
    $done = 0;

    return app(MediaMover::class)->copy('server', 'bucket', ['originals', 'public'], false, false, $chunk, fn () => null, function () use (&$done): void {
        $done++;
    }, function () use (&$done, $after): bool {
        return $done >= $after;
    });
}

/**
 * A faked disk whose writes damage the given paths.
 *
 * @param  array<string, 'truncate'|'flip'>  $damage
 */
function mvCorrupting(string $disk, array $damage): void
{
    $root = storage_path('framework/testing/disks/'.$disk.'-corrupting');
    (new Illuminate\Filesystem\Filesystem)->cleanDirectory($root);
    $adapter = new CorruptingAdapter($root);

    foreach ($damage as $path => $how) {
        $adapter->damage($path, $how);
    }

    Storage::set($disk, new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root, 'url' => 'https://cdn.example.test']));
}

function mvHoldLock(): void
{
    DB::connectUsing('mv_other_session', [...DB::connection()->getConfig(), 'name' => 'mv_other_session']);
    DB::connection('mv_other_session')->selectOne('SELECT pg_try_advisory_lock(hashtext(?))', [MediaMoveLock::KEY]);
}

it('copies every file the rows name, trashed items too, and says the run is verified', function (): void {
    $photo = mvItem('image', ['w640', 'w1280', 'thumb', 'share-1200x630']);
    mvItem('document', []);
    mvItem('image', ['w640'], trashed: true);
    $before = mvFiles('server');

    [$code, $output] = mvRun(['--from' => 'server', '--to' => 'bucket']);
    $run = MediaMove::query()->sole();

    expect($code)->toBe(0)
        ->and(array_values(mvFiles('bucket')))->toBe(array_values($before))
        ->and(mvFiles('bucket'))->not->toContain(null)
        ->and($run->state)->toBe('finished')
        ->and($run->kind)->toBe('copy')
        ->and($run->failures)->toBe(0)
        ->and($run->files_copied)->toBe(8)
        ->and($run->items_done)->toBe(3)
        ->and($run->cursor)->toBe(DB::table('media')->max('id'))
        ->and(trim((string) Str::of($output)->explode("\n")->filter()->last()))->toStartWith('Verified: every file the media rows name is in “bucket”.')
        ->and(Storage::disk('media-originals-bucket')->exists(DB::table('media')->where('id', $photo)->value('original_path')))->toBeTrue();
});

it('counts items, files and bytes from the rows on a dry run, and writes nothing', function (): void {
    mvItem('image', ['w640', 'thumb']);
    mvItem('document', []);

    [$code, $output] = mvRun(['--from' => 'server', '--to' => 'bucket', '--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('2 media items, 4 files')
        ->and(MediaMove::query()->count())->toBe(0)
        ->and(Storage::disk('media-bucket')->allFiles())->toBe([])
        ->and(Storage::disk('media-originals-bucket')->allFiles())->toBe([]);
});

it('resumes a stopped run from its cursor, counting the cut chunk’s files present, not copying them twice', function (): void {
    foreach (range(1, 5) as $n) {
        mvItem('image', ['w640']);
    }

    $stopped = mvStoppedAfter(3, chunk: 2);
    $run = MediaMove::query()->sole();

    expect($stopped['code'])->toBe(MediaMover::STOPPED)
        ->and($run->state)->toBe('stopped')
        ->and($run->items_done)->toBe(3)
        ->and($run->files_copied)->toBe(6);

    [$code] = mvRun(['--from' => 'server', '--to' => 'bucket']);
    $run->refresh();

    expect($code)->toBe(0)
        ->and(MediaMove::query()->count())->toBe(1)
        ->and($run->state)->toBe('finished')
        ->and($run->items_done)->toBe(5)
        ->and($run->files_copied)->toBe(10)
        ->and($run->files_present)->toBe(0)
        ->and(mvFiles('bucket'))->not->toContain(null);
});

it('finds every file already there on a second full run, and copies none', function (): void {
    foreach (range(1, 3) as $n) {
        mvItem('image', ['w640']);
    }

    mvRun(['--from' => 'server', '--to' => 'bucket']);
    // A second full run in the same direction finds every file there.
    [$code] = mvRun(['--from' => 'server', '--to' => 'bucket']);
    $second = MediaMove::query()->orderByDesc('started_at')->orderByDesc('id')->first();

    expect($code)->toBe(0)
        ->and(MediaMove::query()->count())->toBe(2)
        ->and($second->files_copied)->toBe(0)
        ->and($second->files_present)->toBe(6);
});

it('fails an original damaged on its way, never counting it copied', function (): void {
    $id = mvItem('image', ['w640']);
    mvCorrupting('media-originals-bucket', ["{$id}/v1/item.jpg" => 'flip']);

    [$code, $output] = mvRun(['--from' => 'server', '--to' => 'bucket']);
    $run = MediaMove::query()->sole();

    expect($code)->toBe(1)
        ->and($run->failures)->toBe(1)
        ->and($run->files_copied)->toBe(1)
        ->and($run->problems)->toEqual([['id' => $id, 'role' => 'originals', 'path' => "{$id}/v1/item.jpg", 'reason' => 'the copy read back differs from the original']])
        ->and($output)->toContain('Not verified: 1 files failed.');
});

it('passes a variant damaged in place by size alone, fails it with --deep, and fails a cut-short one either way', function (): void {
    $id = mvItem('image', ['w640', 'thumb']);
    mvCorrupting('media-bucket', ["{$id}/v1/item-w640.webp" => 'flip', "{$id}/v1/item-thumb.webp" => 'truncate']);

    [$code] = mvRun(['--from' => 'server', '--to' => 'bucket']);
    $shallow = MediaMove::query()->sole();

    expect($code)->toBe(1)
        ->and(array_column($shallow->problems, 'path'))->toBe(["{$id}/v1/item-thumb.webp"])
        ->and($shallow->problems[0]['reason'])->toBe('its size differs after copying');

    [$deepCode] = mvRun(['--from' => 'server', '--to' => 'bucket', '--deep' => true]);
    $deep = MediaMove::query()->where('deep', true)->sole();

    expect($deepCode)->toBe(1)
        ->and(array_column($deep->problems, 'path'))->toEqualCanonicalizing(["{$id}/v1/item-w640.webp", "{$id}/v1/item-thumb.webp"]);
});

it('refuses to copy an original whose copy on the server no longer matches its recorded checksum', function (): void {
    $id = mvItem('image', []);
    Storage::disk('media-originals')->put("{$id}/v1/item.jpg", 'changed on the server');

    [$code] = mvRun(['--from' => 'server', '--to' => 'bucket']);
    $run = MediaMove::query()->sole();

    expect($code)->toBe(1)
        ->and($run->problems[0]['reason'])->toBe('the copy in “server” differs from its recorded checksum')
        ->and(Storage::disk('media-originals-bucket')->exists("{$id}/v1/item.jpg"))->toBeFalse();
});

it('counts a file missing in both places as a hole, not a failure, and one already moved as present', function (): void {
    $gone = mvItem('image', ['w640'], withFiles: false);
    $moved = mvItem('document', []);
    Storage::disk('media-bucket')->put("{$moved}/v1/item.pdf", Storage::disk('media')->get("{$moved}/v1/item.pdf"));
    Storage::disk('media')->delete("{$moved}/v1/item.pdf");

    [$code, $output] = mvRun(['--from' => 'server', '--to' => 'bucket']);
    $run = MediaMove::query()->sole();

    expect($code)->toBe(0)
        ->and($run->holes)->toBe(2)
        ->and($run->failures)->toBe(0)
        ->and($run->files_present)->toBe(1)
        ->and(collect($run->problems)->pluck('id')->unique()->all())->toBe([$gone])
        ->and($output)->toContain('2 files were missing in both locations; teme:media:move --status names them.');
});

it('copies on a catch-up only what changed since the last finished copy began, and records when', function (): void {
    $old = mvItem('image', ['w640'], updatedAt: now()->subDay());
    mvRun(['--from' => 'server', '--to' => 'bucket']);
    $full = MediaMove::query()->sole();
    $new = mvItem('image', ['w640'], updatedAt: now()->addSecond());
    Storage::disk('media-bucket')->delete("{$old}/v1/item-w640.webp");

    [$code] = mvRun(['--from' => 'server', '--to' => 'bucket', '--catch-up' => true]);
    $catchUp = MediaMove::query()->where('scope', 'changed')->sole();

    expect($code)->toBe(0)
        ->and($catchUp->changed_since->equalTo($full->started_at))->toBeTrue()
        ->and($catchUp->items_total)->toBe(1)
        ->and($catchUp->files_copied)->toBe(2)
        ->and(Storage::disk('media-originals-bucket')->exists("{$new}/v1/item.jpg"))->toBeTrue()
        ->and(Storage::disk('media-bucket')->exists("{$old}/v1/item-w640.webp"))->toBeFalse();
});

it('copies no image original with --only=public', function (): void {
    $photo = mvItem('image', ['w640']);
    $report = mvItem('document', []);

    [$code] = mvRun(['--from' => 'server', '--to' => 'bucket', '--only' => 'public']);

    expect($code)->toBe(0)
        ->and(Storage::disk('media-originals-bucket')->exists("{$photo}/v1/item.jpg"))->toBeFalse()
        ->and(Storage::disk('media-bucket')->exists("{$photo}/v1/item-w640.webp"))->toBeTrue()
        ->and(Storage::disk('media-bucket')->exists("{$report}/v1/item.pdf"))->toBeTrue()
        ->and(MediaMove::query()->sole()->roles)->toBe(['public']);
});

it('refuses before writing anything', function (array $options, Closure $arrange, string $sentence): void {
    mvItem('image', ['w640']);
    $arrange();

    [$code, $output] = mvRun($options);

    expect($code)->toBe(2)
        ->and($output)->toContain($sentence)
        ->and(MediaMove::query()->count())->toBe(0)
        ->and(Storage::disk('media-originals-bucket')->allFiles())->toBe([]);
})->with([
    'the same place' => [['--from' => 'server', '--to' => 'server'], fn () => null, 'already in “server”'],
    'an unknown place' => [['--from' => 'server', '--to' => 'cloud'], fn () => null, 'There is no media location “cloud”'],
    'no public address' => [['--from' => 'server', '--to' => 'bucket'], fn () => Storage::fake('media-bucket'), 'has no public address'],
    'the lock held' => [['--from' => 'server', '--to' => 'bucket'], fn () => mvHoldLock(), 'Another media move is running'],
    'a catch-up with nothing before it' => [['--from' => 'server', '--to' => 'bucket', '--catch-up' => true], fn () => null, 'Run a full copy first'],
]);

it('sends a copy to a real bucket client as Teme sends it, with the original read back', function (): void {
    $id = mvItem('image', []);

    StorageEnvironment::with(StorageEnvironment::bucket(), function () use ($id): void {
        Storage::fake('media');
        Storage::fake('media-originals');
        Storage::disk('media-originals')->put("{$id}/v1/item.jpg", "original bytes of {$id}");
        $bucket = FakeBucket::on('media-originals-bucket');
        FakeBucket::on('media-bucket');

        [$code] = mvRun(['--from' => 'server', '--to' => 'bucket']);

        expect($code)->toBe(0)
            ->and($bucket->objects["{$id}/v1/item.jpg"])->toBe("original bytes of {$id}")
            ->and($bucket->last('PutObject')['params']['ACL'])->toBe('private')
            ->and($bucket->names())->toContain('GetObject');
    });
});

it('lists the last runs with --status', function (): void {
    mvItem('image', ['w640']);
    mvRun(['--from' => 'server', '--to' => 'bucket']);

    [$code, $output] = mvRun(['--status' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('copy server → bucket')->toContain('finished');
});

it('moves back from the bucket to the server', function (): void {
    $id = mvItem('image', ['w640']);
    mvRun(['--from' => 'server', '--to' => 'bucket']);
    Storage::fake('media', ['url' => 'http://localhost/storage/media']);
    Storage::fake('media-originals');

    [$code] = mvRun(['--from' => 'bucket', '--to' => 'server']);

    expect($code)->toBe(0)
        ->and(Storage::disk('media-originals')->get("{$id}/v1/item.jpg"))->toBe("original bytes of {$id}")
        ->and(Storage::disk('media')->exists("{$id}/v1/item-w640.webp"))->toBeTrue();
});
